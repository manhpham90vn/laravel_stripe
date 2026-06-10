<?php

namespace App\Jobs;

use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * LƯỚI ĐỠ cho webhook bị mất/đến trễ (jobs_and_scheduler §5): đối chiếu các đơn
 * còn "sống" với Stripe để trạng thái DB ↔ Stripe hội tụ trong ~15 phút kể cả
 * khi webhook thất lạc. Idempotent — dùng lại CHÍNH PaymentEventHandler mà webhook
 * dùng (NFR-2), nên không gây tác dụng phụ kép.
 *
 * Hai chế độ chạy:
 *   - shallow (mỗi 15'): chỉ quét đơn live gần đây (giới hạn 200, trong 30 ngày).
 *   - deep (hằng ngày): quét TẤT CẢ, gồm cả đơn đã chết (canceled/failed).
 */
class ReconcileStripeOrders implements ShouldQueue
{
    use Queueable;

    public function __construct(public bool $deep = false) {}

    public function handle(PaymentGateway $gateway, PaymentEventHandler $handler): void
    {
        $minutes = (int) config('payment.reconcile.stuck_after_minutes', 30);

        // Luôn quét đơn live; bản DEEP quét THÊM đơn đã chết (§8.2a) để một
        // `succeeded` về sau khi chỗ đã nhả vẫn được reclaim hoặc refund kể cả
        // khi webhook của nó bị mất. (Đây là yêu cầu bắt buộc của §8.2a #4.)
        $statuses = $this->deep
            ? [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_CANCELED, Order::STATUS_FAILED]
            : [Order::STATUS_PENDING, Order::STATUS_PROCESSING];

        $query = Order::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('stripe_payment_intent_id')
            // Chỉ đụng đơn đủ "cũ" để không can thiệp checkout đang diễn ra.
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->orderBy('id');

        // Deep dùng lazy() để stream khỏi tốn RAM; shallow giới hạn 200/lần.
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
            return; // chưa có PaymentIntent — job TTL sẽ hủy nếu bị bỏ rơi
        }

        // B. Đối chiếu tiền (AC-9): amount/currency phải khớp snapshot.
        // amount_received là số đã chốt của một PI succeeded (BR-11).
        $stripeAmount = $pi['amount_received'] ?? ($pi['amount'] ?? null);
        if ($stripeAmount !== null && (int) $stripeAmount !== (int) $order->amount) {
            Log::warning('Reconcile: amount mismatch', [
                'order' => $order->id, 'db' => $order->amount, 'stripe' => $stripeAmount,
            ]);
        }

        // A. Hội tụ trạng thái đơn theo trạng thái PaymentIntent.
        match ($pi['status'] ?? null) {
            'succeeded' => $handler->markPaid($order, [
                'payment_method_type' => $pi['payment_method_type'] ?? $order->payment_method_type,
                'charge_id' => $pi['latest_charge'] ?? null,
                'payment_intent_id' => $pi['id'] ?? null,
                'amount' => $stripeAmount,
            ]),
            'canceled' => $handler->markFailed($order, ['reason' => 'reconcile: PaymentIntent canceled']),
            default => null, // processing/requires_* → để yên; job TTL lo việc hết hạn
        };
    }
}
