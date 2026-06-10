<?php

namespace App\Jobs;

use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Quét các đơn đã HẾT HẠN GIỮ CHỖ (hết TTL mà chưa trả) và nhả chỗ của chúng
 * (spec §6 Phương án A, §7.3; AC-5). Chạy theo lịch MỖI PHÚT.
 *
 * Đơn async (`processing`) mang `reserved_until` dài = hạn voucher, nên chỉ bị
 * quét khi mốc đó thật sự trôi qua (BR-8) — xem ReservationService::extendForAsync.
 *
 * TRƯỚC khi nhả chỗ, job kiểm PaymentIntent (spec §1.3): khoản đang bay (3DS /
 * voucher) được cho thêm thời gian, khoản đã `succeeded` thì hội tụ về paid thay
 * vì bị nhả — đây là LỚP PHÒNG NGỪA cho race "nhả đúng lúc tiền đang về" (§8.2a).
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Queueable;

    /** Các trạng thái PI nghĩa là "tiền có thể vẫn đang về — đừng nhả". */
    private const LIVE_PI = ['processing', 'requires_action', 'requires_capture'];

    public function handle(PaymentGateway $gateway, PaymentEventHandler $handler): void
    {
        Order::query()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())   // chỉ đơn đã quá hạn giữ
            ->orderBy('id')
            ->each(function (Order $order) use ($gateway, $handler) {
                // Có PI thì hỏi Stripe trước; tiền đang bay / đã về → đừng nhả.
                if ($order->stripe_payment_intent_id && $this->deferToPayment($order, $gateway, $handler)) {
                    return;
                }

                // expire() → hủy đơn + nhả chỗ + chủ động đóng Checkout Session (§8.4).
                $handler->expire($order);
            });
    }

    /**
     * Trả về true khi KHÔNG được nhả chỗ vì tiền có thể đang/đã về.
     *
     * Đây là LỚP CHỐNG NHẢ OAN — cực kỳ quan trọng cho luồng async (konbini) khi
     * webhook về trễ. Tình huống: người mua vừa đặt voucher ở phút 14:59, nhưng
     * webhook `payment_intent.processing` bị nghẽn queue chưa xử lý kịp, nên đơn
     * vẫn đang `pending` với `reserved_until` vừa hết hạn → job quét trúng nó.
     * Nếu nhả mù ở đây thì sẽ giết một đơn konbini hợp lệ đang chờ tiền vài ngày.
     *
     * Cách chặn: TRƯỚC khi nhả, hỏi thẳng Stripe trạng thái PaymentIntent:
     *   • processing / requires_action / requires_capture (voucher đã đặt, hoặc
     *     thẻ đang 3DS, hoặc chờ capture) → tiền đang bay → GIA HẠN, không nhả.
     *   • succeeded → tiền đã về (webhook bị mất) → đẩy thẳng lên paid, không nhả.
     *   • còn lại (PI chết / chưa từng tới bước trả) → mới thật sự nhả.
     *
     * Lỗi khi gọi Stripe cũng trả true (giữ chỗ) — thà giữ nhầm còn hơn nhả oan
     * lúc tiền có thể đang về; job reconcile là lưới đỡ cuối.
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
            // PI còn sống → gia hạn thêm để chờ. Lưu ý: nới bằng MỐC THẺ (~15')
            // chứ không phải mốc async, vì đây chỉ là "nới tạm cho lần quét sau".
            // Với konbini, webhook processing khi về sẽ gọi extendForAsync() đẩy
            // hold ra đúng vài ngày. Còn nếu webhook mất hẳn, mỗi lần quét (mỗi
            // phút) thấy PI vẫn processing sẽ lại nới 15' → chỗ vẫn được giữ liên
            // tục tới khi voucher được trả (succeeded) hoặc hết hạn (payment_failed).
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
                'amount' => $pi['amount_received'] ?? ($pi['amount'] ?? null),  // BR-11
            ]);

            return true;
        }

        return false; // PI is dead / never started — safe to release
    }
}
