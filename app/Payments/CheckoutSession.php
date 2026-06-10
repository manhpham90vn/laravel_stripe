<?php

namespace App\Payments;

/**
 * DTO trả về khi khởi tạo checkout hosted: URL để redirect trình duyệt sang
 * trang thanh toán + tham chiếu của nhà cung cấp (PaymentIntent / Session id).
 * `readonly` để bất biến — chỉ là vật mang dữ liệu, không có hành vi.
 */
readonly class CheckoutSession
{
    public function __construct(
        public string $redirectUrl,    // URL trang thanh toán Stripe để redirect tới
        public string $providerRef,    // id PaymentIntent / Session bên Stripe
    ) {}
}
