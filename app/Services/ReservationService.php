<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phương án A — Reserve-with-timeout (spec §6): chiếm chỗ NGAY khi bấm "Mua",
 * giữ trong TTL, hết hạn chưa trả thì nhả.
 *
 * BẤT BIẾN CỐT LÕI: MỌI thay đổi `sale_batches.slots_taken` đều nằm trong một
 * transaction đang GIỮ ROW LOCK trên dòng batch (`lockForUpdate`). Đây chính là
 * thứ khiến KHÔNG THỂ bán vượt số lượng dù nhiều người bấm mua cùng lúc — row
 * lock tuần tự hóa họ lại (NFR-1 / BR-1). Không bao giờ đọc slots_taken ngoài
 * transaction để "quyết định bán".
 */
class ReservationService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Chiếm 1 chỗ cho $user trong $batch và tạo đơn `pending`.
     *
     * Toàn bộ chạy trong 1 transaction có row lock batch → các bước guard
     * (cửa sổ bán / còn chỗ / BR-2) và tăng slots_taken là NGUYÊN TỬ với nhau.
     *
     * @throws CheckoutException SOLD_OUT | ALREADY_PURCHASED | BATCH_NOT_ON_SALE
     */
    public function reserve(SaleBatch $batch, User $user): Order
    {
        return DB::transaction(function () use ($batch, $user) {
            // Row lock — tuần tự hóa những người mua đang tranh cùng một batch.
            $batch = SaleBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            // Guard 1: batch phải đang mở bán và trong cửa sổ thời gian.
            if ($batch->status !== SaleBatch::STATUS_ON_SALE || ! $batch->isWithinWindow()) {
                throw CheckoutException::notOnSale();
            }

            // Guard 2: còn chỗ không? (chặn overselling — đọc trong lock nên chuẩn)
            if ($batch->slots_taken >= $batch->capacity) {
                throw CheckoutException::soldOut();
            }

            // Guard 3 (BR-2): mỗi (user, batch) tối đa 1 đơn live / 1 reservation
            // active. Check này chạy dưới row lock nên hai request cùng user bị
            // tuần tự hóa → cái sau thấy cái trước và bị chặn.
            $hasLiveOrder = Order::where('sale_batch_id', $batch->id)
                ->where('user_id', $user->id)
                ->whereIn('status', Order::LIVE_STATUSES)
                ->exists();

            $hasActiveReservation = Reservation::where('sale_batch_id', $batch->id)
                ->where('user_id', $user->id)
                ->where('status', Reservation::STATUS_ACTIVE)
                ->exists();

            if ($hasLiveOrder || $hasActiveReservation) {
                throw CheckoutException::alreadyPurchased();
            }

            // TTL ban đầu LUÔN là mốc ngắn của thẻ (~15'), kể cả khi sau này
            // người mua chọn konbini/pay-easy. Lý do: ta dùng Stripe Checkout
            // hosted nên TẠI THỜI ĐIỂM NÀY chưa biết người mua sẽ trả bằng gì —
            // họ mới bấm "Mua", chưa sang trang Stripe để chọn phương thức.
            // 15' ở đây = "hạn để LẤY ĐƯỢC voucher / hoàn tất trang Checkout",
            // KHÔNG phải hạn trả tiền. Khi voucher async được đặt xong, webhook
            // sẽ gọi extendForAsync() để đẩy hold ra vài ngày (xem hàm đó).
            $ttl = now()->addMinutes((int) config('payment.ttl.card_minutes'));

            $reservation = Reservation::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $user->id,
                'status' => Reservation::STATUS_ACTIVE,
                'reserved_until' => $ttl,
            ]);

            $batch->slots_taken++;
            if ($batch->slots_taken >= $batch->capacity) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_SOLD_OUT;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_SOLD_OUT, 'system');
            }
            $batch->save();

            $order = Order::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $user->id,
                'reservation_id' => $reservation->id,
                'status' => Order::STATUS_PENDING,
                'amount' => $batch->price,                  // BR-3: server-side snapshot
                'currency' => $batch->currency,
                'reserved_until' => $ttl,
            ]);

            $this->audit->record($order, null, Order::STATUS_PENDING, 'user', $user->id);

            return $order;
        });
    }

    /**
     * Nhả một chỗ đang giữ: đổi trạng thái reservation + giảm slots_taken.
     * Idempotent — reservation không còn `active` thì là no-op (đã consume/nhả
     * rồi), nên gọi trùng từ nhiều nguồn (job TTL, webhook failed…) vẫn an toàn.
     *
     * $toStatus: 'expired' (hết TTL) hoặc 'released' (hủy/thất bại) — để phân
     * biệt lý do nhả khi đối soát.
     */
    public function release(Reservation $reservation, string $toStatus = Reservation::STATUS_EXPIRED): void
    {
        DB::transaction(function () use ($reservation, $toStatus) {
            // Khóa cả batch lẫn reservation để cập nhật slots_taken an toàn.
            $batch = SaleBatch::whereKey($reservation->sale_batch_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                return; // đã consumed/released/expired → không nhả lần hai
            }

            $reservation->status = $toStatus;
            $reservation->save();

            if ($batch->slots_taken > 0) {
                $batch->slots_taken--;
            }

            // Chỗ vừa nhả có thể MỞ LẠI một batch đã sold_out nếu vẫn trong cửa sổ.
            if ($batch->status === SaleBatch::STATUS_SOLD_OUT && $batch->isWithinWindow()) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_ON_SALE;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_ON_SALE, 'system');
            }
            $batch->save();
        });
    }

    /**
     * GIÀNH LẠI chỗ cho một đơn mà chỗ đã bị nhả — tức `payment_intent.succeeded`
     * về MUỘN trên đơn đã canceled/failed (spec §8.2a reclaim-or-refund).
     *
     * Trả về:
     *   true  → giành được chỗ (caller sẽ lên paid + cấp enrollment).
     *   false → hết chỗ HOẶC user đã có đơn live khác cho batch này (BR-2) →
     *           caller PHẢI refund khoản thu muộn thay vì hồi sinh đơn này.
     *
     * Chạy trong lock batch để việc kiểm-tra-và-chiếm chỗ là nguyên tử.
     */
    public function reclaim(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $batch = SaleBatch::whereKey($order->sale_batch_id)->lockForUpdate()->firstOrFail();

            // Đã có đơn live/paid KHÁC của (user, batch) này đang giữ chỗ — hồi
            // sinh đơn này nữa là double-book, phá BR-2.
            $hasOtherLive = Order::where('sale_batch_id', $batch->id)
                ->where('user_id', $order->user_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', Order::LIVE_STATUSES)
                ->exists();

            if ($hasOtherLive || $batch->slots_taken >= $batch->capacity) {
                return false; // hết chỗ hoặc trùng đơn → caller refund
            }

            // Reservation cũ đã mất; tạo một reservation `consumed` mới để chỗ
            // được gán vĩnh viễn cho đơn này.
            $reservation = Reservation::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $order->user_id,
                'status' => Reservation::STATUS_CONSUMED,
                'reserved_until' => now(),
            ]);

            $batch->slots_taken++;
            if ($batch->slots_taken >= $batch->capacity && $batch->status === SaleBatch::STATUS_ON_SALE) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_SOLD_OUT;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_SOLD_OUT, 'system');
            }
            $batch->save();

            $order->update(['reservation_id' => $reservation->id]);

            return true;
        });
    }

    /** Đánh dấu reservation đã `consumed` (chỗ giờ bị chiếm vĩnh viễn — đơn đã paid). */
    public function consume(Reservation $reservation): void
    {
        if ($reservation->status === Reservation::STATUS_ACTIVE) {
            $reservation->update(['status' => Reservation::STATUS_CONSUMED]);
        }
    }

    /**
     * Nới hạn giữ chỗ cho phương thức bất đồng bộ (Konbini / Pay-easy / bank) — BR-8.
     *
     * ĐÂY LÀ MẤU CHỐT trả lời câu "hold 15' mà konbini trả sau vài ngày thì sao":
     * 15' chỉ là hạn để LẤY voucher; ngay khi voucher được đặt, hold nhảy ra vài ngày.
     *
     * Dòng thời gian của một đơn konbini:
     *
     *   t=0      Bấm "Mua" → reserve(): order=pending, reserved_until = +15' (mốc thẻ)
     *   t=0..15' Người mua ở trang Stripe Checkout, chọn Konbini, nhận mã voucher
     *   t≈5'     Voucher được tạo → Stripe bắn webhook:
     *              • checkout.session.completed (payment_status = unpaid)
     *              • payment_intent.processing
     *            → PaymentEventHandler::markProcessing() gọi HÀM NÀY:
     *              order: pending → processing
     *              reserved_until: +15'  ───►  +async_days (vài NGÀY)   ◄── hold nhảy ra đây
     *   t=0..vài ngày  Người mua ra cửa hàng tiện lợi trả tiền trong hạn voucher
     *   t≈2 ngày  Trả xong → payment_intent.succeeded → markPaid(): paid + cấp enrollment
     *
     * Vì job nhả chỗ (ReleaseExpiredReservations) chỉ quét đơn có
     * `reserved_until < now`, mà sau khi nới nó là +vài ngày, nên đơn processing
     * KHÔNG bị nhả ở phút 15. Hạn trả tiền thật = hạn của voucher, không phải 15'.
     *
     * Lưu ý kỹ thuật:
     *  - Nới CẢ reservation lẫn order. Reservation chỉ nới khi còn `active` (chưa bị
     *    consume/release) để không hồi sinh một reservation đã chết.
     *  - `now + async_days` là XẤP XỈ hạn voucher (voucher hết hạn tính từ lúc tạo
     *    session, sớm hơn lúc gọi hàm này vài phút). Lệch theo hướng AN TOÀN: ta giữ
     *    chỗ lâu hơn voucher một chút, nên không bao giờ nhả chỗ trước khi voucher hết
     *    hạn. Voucher hết hạn mà chưa trả → Stripe bắn payment_failed → đơn bị hủy.
     *  - async_days nên được kẹp [1,60] cho khớp `expires_after_days` của konbini bên
     *    StripeGateway::checkoutParams() (xem ghi chú ở đó). [[ttl-async-clamp]]
     */
    public function extendForAsync(Order $order): void
    {
        $until = now()->addDays((int) config('payment.ttl.async_days'));

        if ($order->reservation && $order->reservation->status === Reservation::STATUS_ACTIVE) {
            $order->reservation->update(['reserved_until' => $until]);
        }
        $order->update(['reserved_until' => $until]);
    }
}
