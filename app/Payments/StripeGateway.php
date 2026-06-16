<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Cài đặt cụ thể của PaymentGateway dùng STRIPE CHECKOUT HOSTED (D7): ta redirect
 * người mua sang trang trả tiền do Stripe host, Stripe lo SCA/3DS và Konbini.
 *
 * Cấu hình qua STRIPE_SECRET / STRIPE_WEBHOOK_SECRET (dùng key test khi dev).
 * Toàn bộ thao tác trạng thái tiền KHÔNG nằm ở đây — chúng đến qua webhook và
 * được PaymentEventHandler xử lý; lớp này chỉ là "vỏ" gọi API Stripe.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(
        private string $secret,
        private ?string $webhookSecret,
    ) {}

    private function client(): StripeClient
    {
        return new StripeClient($this->secret);
    }

    /**
     * Tạo Checkout Session và lưu các tham chiếu Stripe vào đơn. Có gắn
     * idempotency_key (§8.1) để retry không tạo session trùng.
     */
    public function createCheckout(Order $order): CheckoutSession
    {
        $order->loadMissing('saleBatch.course');

        // §2.16: một đơn chỉ được có TỐI ĐA 1 phiên sống. Khi retry (đơn đã có
        // cs_ cũ còn open ở tab khác), đóng phiên cũ TRƯỚC khi mở phiên mới để
        // người mua không trả tiền vào phiên cũ (giá/đơn có thể đã đổi) hay trả
        // trên cả hai. No-op an toàn khi chưa có phiên (đơn mới) hoặc phiên đã đóng.
        $this->expireCheckout($order);

        $session = $this->client()->checkout->sessions->create($this->checkoutParams($order), [
            'idempotency_key' => $this->idempotencyKey('checkout', $order),   // spec §8.1
        ]);

        $order->update([
            // Trong mode 'payment', Stripe tạo luôn PaymentIntent cùng session →
            // lưu pi_; nếu chưa có thì tạm lưu session id (cs_).
            'stripe_payment_intent_id' => $session->payment_intent ?? $session->id,
            // Lưu THÊM cs_ id: expireCheckout() cần nó để chủ động đóng cửa trả
            // tiền khi hết TTL/hủy (§8.4). Trước đây pi_ ghi đè mất cs_.
            'stripe_checkout_session_id' => $session->id,
        ]);

        return new CheckoutSession($session->url, $session->id);
    }

    /**
     * CHỦ ĐỘNG đóng Checkout Session để không trả tiền được nữa — gọi khi hết
     * slot-hold TTL hoặc người mua hủy (§8.4), đóng cửa ở ~15' thay vì đợi hết
     * mốc sàn 30' của expires_at.
     *
     * Stripe chỉ `expire` được session còn `open`; session đã completed/expired
     * sẽ ném InvalidRequestException — với ta là no-op (tiền hoặc đã về → đã có
     * reclaim-or-refund lo, hoặc cửa đã đóng sẵn). Mọi lỗi đều bị NUỐT để không
     * bao giờ chặn việc nhả chỗ.
     */
    public function expireCheckout(Order $order): void
    {
        $sessionId = $order->stripe_checkout_session_id;
        if (! $sessionId || ! str_starts_with($sessionId, 'cs_')) {
            return; // không có session sống để đóng (voucher async / chưa bắt đầu)
        }

        try {
            $this->client()->checkout->sessions->expire($sessionId);
        } catch (\Throwable $e) {
            Log::info('Checkout session already closed — nothing to expire', [
                'order' => $order->id, 'session' => $sessionId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dựng mảng tham số cho Checkout Session. Tách riêng khỏi createCheckout để
     * có thể unit-test các quy tắc tiền/method/voucher mà KHÔNG cần gọi Stripe.
     *
     * Điểm cốt yếu: `unit_amount` lấy từ `order->amount` (snapshot server-side,
     * BR-3) — không bao giờ nhận amount từ client.
     *
     * @return array<string,mixed>
     */
    public function checkoutParams(Order $order): array
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => (int) $order->amount,   // JPY zero-decimal
                    'product_data' => ['name' => $order->saleBatch->course->title.' — '.$order->saleBatch->name],
                ],
            ]],
            'success_url' => route('orders.show', $order),
            'cancel_url' => route('batches.show', $order->sale_batch_id),
            'payment_intent_data' => [
                'metadata' => $this->metadata($order),
            ],
            'metadata' => $this->metadata($order),
            // Kẹp vòng đời session theo slot-hold của ta thay vì mặc định 24h của
            // Stripe, để người mua không trả tiền lâu sau khi chỗ đã nhả. Stripe
            // từ chối expires_at < 30' nên kẹp SÀN 30' (§8.4); khe 15'→30' còn lại
            // được reclaim-or-refund che.
            'expires_at' => now()->addMinutes(
                max(30, (int) config('payment.ttl.session_minutes'))
            )->timestamp,
        ];

        // Giới hạn phương thức theo cấu hình (mặc định: card). Để rỗng thì Stripe
        // hiện mọi method đang bật trong Dashboard cho loại tiền này.
        $methods = config('payment.payment_methods');
        if (! empty($methods)) {
            $params['payment_method_types'] = $methods;
        }

        // Konbini: ghim hạn voucher = TTL async của ta để chỗ được giữ đúng bằng
        // thời gian khách phải trả, không dài hơn (§8.4 / BR-8). Không ghim thì
        // voucher dùng mặc định Stripe (~3 ngày), dễ lệch reserved_until.
        // LƯU Ý: kẹp [1,60] ở đây PHẢI khớp với extendForAsync() (xem [[ttl-async-clamp]]).
        if (empty($methods) || in_array('konbini', $methods, true)) {
            $days = max(1, min(60, (int) config('payment.ttl.async_days')));
            $params['payment_method_options']['konbini']['expires_after_days'] = $days;
        }

        return $params;
    }

    /** Hoàn tiền một đơn đã thu. Kết quả (charge.refunded) sẽ về qua webhook. */
    public function refund(Order $order): void
    {
        if (! $order->stripe_charge_id) {
            throw new RuntimeException("Order {$order->id} has no charge to refund.");
        }

        $this->client()->refunds->create([
            'charge' => $order->stripe_charge_id,
            'metadata' => $this->metadata($order),
        ], [
            'idempotency_key' => $this->idempotencyKey('refund', $order),
        ]);
    }

    /**
     * Idempotency key gắn với INSTANCE của đơn, không chỉ khóa chính.
     * Lý do: id auto-increment bị TÁI SỬ DỤNG sau khi reset DB (migrate:fresh),
     * mà Stripe giữ idempotency key ~24h — nên keying theo mỗi id sẽ khiến một id
     * tái dụng đụng request cũ khác tham số ("Keys for idempotent requests can
     * only be used with the same parameters"). Trộn thêm created_at giữ retry
     * cùng-đơn vẫn idempotent, đồng thời tách biệt các id tái dụng.
     */
    private function idempotencyKey(string $action, Order $order): string
    {
        return $action.'_order_'.$order->id.'_'.optional($order->created_at)->getTimestamp();
    }

    /**
     * Lấy PaymentIntent hiện tại của đơn dưới dạng mảng (dùng cho job reconcile /
     * TTL để hội tụ DB ↔ Stripe). Trả null nếu chưa có PI.
     */
    public function retrievePaymentIntentForOrder(Order $order): ?array
    {
        $ref = $order->stripe_payment_intent_id;
        if (! $ref) {
            return null;
        }

        $client = $this->client();
        $piId = $ref;

        // Trước khi checkout.session.completed về, ta mới chỉ có session id (cs_);
        // phải resolve nó ra PaymentIntent thật trước.
        if (str_starts_with($ref, 'cs_')) {
            $session = $client->checkout->sessions->retrieve($ref);
            $piId = $session->payment_intent;
            if (! $piId) {
                return null; // người mua chưa từng tới bước thanh toán
            }
        }

        return $client->paymentIntents->retrieve($piId)->toArray();
    }

    /**
     * Xác thực CHỮ KÝ webhook (Stripe-Signature) bằng webhook secret rồi trả về
     * event dạng mảng. Ném exception nếu chữ ký sai — đây là lớp bảo vệ duy nhất
     * cho endpoint webhook (không auth/CSRF).
     */
    public function constructEvent(string $payload, ?string $signature): array
    {
        $event = Webhook::constructEvent($payload, (string) $signature, (string) $this->webhookSecret);

        return $event->toArray();
    }

    /** metadata gắn lên PI để webhook map ngược về đơn (spec §8.1). */
    private function metadata(Order $order): array
    {
        return [
            'order_id' => (string) $order->id,
            'sale_batch_id' => (string) $order->sale_batch_id,
            'user_id' => (string) $order->user_id,
        ];
    }
}
