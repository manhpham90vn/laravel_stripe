<?php

namespace App\Jobs;

use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps orders whose hold has expired (TTL elapsed before payment) and frees
 * their slot (spec §6 Phương án A, §7.3; AC-5). Scheduled every minute.
 *
 * Async orders (processing) carry a long reserved_until = voucher expiry, so
 * they are only swept once that deadline truly passes (BR-8).
 *
 * Before freeing a slot the job checks the PaymentIntent (spec §1.3): a payment
 * still in flight (3DS / voucher) is given more time, and one that already
 * succeeded is converged to paid instead of being released — this is the
 * prevention layer for the "released while payment was landing" race (§8.2a).
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Queueable;

    /** PaymentIntent statuses that mean "money may still arrive — don't free". */
    private const LIVE_PI = ['processing', 'requires_action', 'requires_capture'];

    public function handle(PaymentGateway $gateway, PaymentEventHandler $handler): void
    {
        Order::query()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->orderBy('id')
            ->each(function (Order $order) use ($gateway, $handler) {
                if ($order->stripe_payment_intent_id && $this->deferToPayment($order, $gateway, $handler)) {
                    return; // payment in flight or already succeeded — don't free
                }

                $handler->expire($order);
            });
    }

    /**
     * Returns true when the slot must NOT be freed because the PaymentIntent is
     * still live (extended) or has already succeeded (converged to paid). Any
     * lookup failure also returns true — better to keep the hold than to free a
     * slot while money might be landing; the reconcile job is the backstop.
     */
    private function deferToPayment(Order $order, PaymentGateway $gateway, PaymentEventHandler $handler): bool
    {
        try {
            $pi = $gateway->retrievePaymentIntentForOrder($order);
        } catch (\Throwable $e) {
            Log::warning('Release: could not retrieve PaymentIntent — leaving hold in place', [
                'order' => $order->id, 'error' => $e->getMessage(),
            ]);

            return true;
        }

        $status = $pi['status'] ?? null;

        if (in_array($status, self::LIVE_PI, true)) {
            $order->update([
                'reserved_until' => now()->addMinutes((int) config('payment.ttl.card_minutes')),
            ]);

            return true;
        }

        if ($status === 'succeeded') {
            $handler->markPaid($order, [
                'payment_method_type' => $pi['payment_method_type'] ?? $order->payment_method_type,
                'charge_id' => $pi['latest_charge'] ?? null,
                'payment_intent_id' => $pi['id'] ?? null,
                'amount' => $pi['amount'] ?? null,
            ]);

            return true;
        }

        return false; // PI is dead / never started — safe to release
    }
}
