<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * CỬA VÀO DUY NHẤT cho mọi sự kiện thanh toán Stripe — dùng chung bởi job xử lý
 * webhook (ProcessStripeEvent) và lưới đỡ reconcile, để cả hai áp y hệt logic.
 *
 * Nhiệm vụ: nhận event đã verify chữ ký → map về đúng Order → route sang đúng
 * method của PaymentEventHandler theo `type`.
 *
 * Idempotency (BR-5): mỗi event.id được ghi vào processed_stripe_events và bỏ
 * qua khi giao lại. Webhook là nguồn sự thật (D5).
 */
class StripeEventProcessor
{
    public function __construct(private PaymentEventHandler $handler) {}

    /**
     * Xử lý một event Stripe: kiểm event.id → pre-check dedup → map về Order →
     * match(type) gọi handler tương ứng.
     *
     * @param array{id:string,type:string,data:array} $event
     */
    public function process(array $event): void
    {
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? '';

        if (! $eventId) {
            Log::warning('Stripe event without id', ['type' => $type]);

            return;
        }

        if (ProcessedStripeEvent::find($eventId)) {
            return; // đã xử lý rồi — pre-check rẻ trước khi làm bất cứ việc gì
        }

        $object = $event['data']['object'] ?? [];
        $order = $this->resolveOrder($object);

        // Dấu processed-event mới là chốt dedup THẬT: mỗi handler ghi dấu TRƯỚC,
        // trong cùng transaction với side-effect (§2.8), nên nếu có bản trùng chạy
        // song song thì nó đụng unique violation và bị loại ở đó. `ctx` mang
        // event id/type vào trong transaction ấy.
        $ctx = ['event_id' => $eventId, 'event_type' => $type];

        if (! $order) {
            // Event không map được về đơn nào (vd event không liên quan) → chỉ
            // ghi dấu để lần retry bỏ qua, không làm gì thêm.
            Log::warning('Stripe event with no matching order', ['type' => $type, 'event' => $eventId]);
            $this->markProcessed($eventId, $type);

            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handler->onCheckoutCompleted($order, $ctx + [
                'payment_intent_id' => $object['payment_intent'] ?? null,
                'payment_status' => $object['payment_status'] ?? null,
                'payment_method_type' => $object['payment_method_types'][0] ?? null,
            ]),
            'payment_intent.succeeded' => $this->handler->markPaid($order, $ctx + [
                'payment_method_type' => $object['payment_method_type'] ?? null,
                'charge_id' => $object['latest_charge'] ?? ($object['charge'] ?? null),
                'payment_intent_id' => $object['id'] ?? null,
                'amount' => $object['amount_received'] ?? ($object['amount'] ?? null),
            ]),
            'payment_intent.processing' => $this->handler->markProcessing($order, $ctx + [
                'payment_method_type' => $object['payment_method_type'] ?? 'konbini',
            ]),
            'payment_intent.payment_failed',
            'payment_intent.canceled' => $this->handler->markFailed($order, $ctx + [
                'reason' => $object['last_payment_error']['message'] ?? null,
            ]),
            'charge.refunded' => $this->handler->markRefunded($order, $ctx + [
                'charge_id' => $object['id'] ?? null,
            ]),
            'charge.dispute.created' => $this->handler->openDispute($order, $ctx + [
                'dispute_id' => $object['id'] ?? null,
                'dispute_status' => $object['status'] ?? null,
            ]),
            'charge.dispute.closed' => $this->handler->closeDispute($order, $ctx + [
                'dispute_id' => $object['id'] ?? null,
                'dispute_status' => $object['status'] ?? null,
            ]),
            // Event ta không quan tâm — vẫn ghi dấu để retry bỏ qua.
            default => $this->onUnhandled($eventId, $type),
        };
    }

    private function onUnhandled(string $eventId, string $type): void
    {
        Log::info('Unhandled Stripe event', ['type' => $type]);
        $this->markProcessed($eventId, $type);
    }

    /**
     * Ghi dấu cho các event KHÔNG đụng tới đơn (không map được / event lạ). Với
     * event CÓ thay đổi đơn thì chính handler ghi dấu BÊN TRONG transaction
     * side-effect, để dấu và thay đổi luôn nguyên tử cùng nhau.
     */
    private function markProcessed(string $eventId, string $type): void
    {
        try {
            ProcessedStripeEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'processed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // A concurrent delivery already recorded it — fine, it's a no-op event.
        }
    }

    /**
     * Map object trong event về Order của ta (spec §8.1), thử lần lượt theo độ
     * tin cậy giảm dần:
     *   1. metadata.order_id (ta tự gắn lúc tạo PI — đáng tin nhất).
     *   2. payment_intent id (dispute/charge mang theo PI; event payment_intent.*
     *      thì chính nó là PI).
     *   3. charge id.
     *   4. object id (khi object chính là PaymentIntent).
     */
    private function resolveOrder(array $object): ?Order
    {
        if ($orderId = $object['metadata']['order_id'] ?? null) {
            return Order::find($orderId);
        }

        // Dispute/charge mang theo PI; event payment_intent.* thì object là PI.
        if ($pi = $object['payment_intent'] ?? null) {
            if ($order = Order::where('stripe_payment_intent_id', $pi)->first()) {
                return $order;
            }
        }

        if ($charge = $object['charge'] ?? null) {
            if ($order = Order::where('stripe_charge_id', $charge)->first()) {
                return $order;
            }
        }

        if ($id = $object['id'] ?? null) {
            return Order::where('stripe_payment_intent_id', $id)->first();
        }

        return null;
    }
}
