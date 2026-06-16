<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/** Lỗi KỸ THUẬT khi tạo Stripe Checkout Session (khác CheckoutException là lỗi nghiệp vụ). */
class GatewayException extends RuntimeException
{
    public function __construct(
        public readonly Order $order,
        string $message = 'Không khởi tạo được thanh toán. Vui lòng thử lại.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
