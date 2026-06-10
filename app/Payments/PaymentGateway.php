<?php

namespace App\Payments;

use App\Models\Order;

/**
 * Hợp đồng (interface) cho cổng thanh toán. Code nghiệp vụ chỉ phụ thuộc vào
 * interface này, không phụ thuộc trực tiếp Stripe — dễ mock trong test và dễ
 * thay nhà cung cấp. Bản cài đặt thực tế là StripeGateway.
 */
interface PaymentGateway
{
    /**
     * Khởi tạo checkout hosted cho đơn. Amount lấy TỪ ĐƠN (snapshot server-side,
     * BR-3), không nhận từ client. Trả về nơi cần redirect trình duyệt tới.
     */
    public function createCheckout(Order $order): CheckoutSession;

    /** Hoàn tiền một đơn đã trả. Thay đổi trạng thái sẽ về qua webhook. */
    public function refund(Order $order): void;

    /**
     * Đóng Checkout Session của đơn để không trả tiền được nữa — gọi khi hết
     * slot-hold TTL hoặc người mua hủy (§8.4). Session đã đóng (paid/expired) là
     * no-op an toàn.
     */
    public function expireCheckout(Order $order): void;

    /**
     * Lấy PaymentIntent hiện tại của đơn dưới dạng mảng chuẩn hóa (các khóa: id,
     * status, amount, currency, payment_method_type, latest_charge), hoặc null
     * nếu chưa có. Dùng bởi lưới đỡ reconcile (jobs_and_scheduler §5) để hội tụ
     * DB ↔ Stripe khi webhook bị mất.
     */
    public function retrievePaymentIntentForOrder(Order $order): ?array;

    /**
     * Xác thực payload webhook và trả về mảng chuẩn hóa gồm: id, type,
     * data.object (kèm metadata.order_id).
     *
     * @throws \Throwable khi chữ ký không hợp lệ
     */
    public function constructEvent(string $payload, ?string $signature): array;
}
