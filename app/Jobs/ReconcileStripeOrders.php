<?php

namespace App\Jobs;

use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for missed/late webhooks (jobs_and_scheduler §5): reconciles
 * still-live orders against Stripe so DB ↔ Stripe state converges within ~15
 * minutes even if a webhook is lost. Idempotent — it reuses the same
 * PaymentEventHandler the webhook does (NFR-2).
 *
 * The shallow run (every 15 min) scans recent live orders; the deep run (daily)
 * sweeps all of them.
 */
class ReconcileStripeOrders implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $deep = false) {}

    public function handle(PaymentGateway $gateway, PaymentEventHandler $handler): void
    {
        $minutes = (int) config('payment.reconcile.stuck_after_minutes', 30);

        // Live orders always; the deep run also sweeps dead orders (§8.2a) so a
        // `succeeded` that landed after the slot was freed still gets reclaimed
        // or refunded even when its webhook was lost.
        $statuses = $this->deep
            ? [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_CANCELED, Order::STATUS_FAILED]
            : [Order::STATUS_PENDING, Order::STATUS_PROCESSING];

        $query = Order::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('stripe_payment_intent_id')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->orderBy('id');

        $orders = $this->deep
            ? $query->lazy()
            : $query->where('created_at', '>', now()->subDays(30))->limit(200)->get();

        foreach ($orders as $order) {
            $this->reconcile($order, $gateway, $handler);
        }
    }

    private function reconcile(Order $order, PaymentGateway $gateway, PaymentEventHandler $handler): void
    {
        try {
            $pi = $gateway->retrievePaymentIntentForOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Reconcile: could not retrieve PaymentIntent', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $pi) {
            return; // no PaymentIntent yet — the TTL job cancels it if abandoned
        }

        // B. Money check (AC-9): the amount/currency must match the snapshot.
        if (isset($pi['amount']) && (int) $pi['amount'] !== (int) $order->amount) {
            Log::warning('Reconcile: amount mismatch', [
                'order' => $order->id, 'db' => $order->amount, 'stripe' => $pi['amount'],
            ]);
        }

        // A. Converge the order status from the PaymentIntent.
        match ($pi['status'] ?? null) {
            'succeeded' => $handler->markPaid($order, [
                'payment_method_type' => $pi['payment_method_type'] ?? $order->payment_method_type,
                'charge_id' => $pi['latest_charge'] ?? null,
                'payment_intent_id' => $pi['id'] ?? null,
                'amount' => $pi['amount'] ?? null,
            ]),
            'canceled' => $handler->markFailed($order, ['reason' => 'reconcile: PaymentIntent canceled']),
            default => null, // processing/requires_* → leave; TTL job handles expiry
        };
    }
}
