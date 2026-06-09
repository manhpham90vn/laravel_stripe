<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A business-rule rejection during checkout. The `code` is the internal
 * identifier from spec §9; the message is shown to the buyer via flash.
 */
class CheckoutException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function soldOut(): self
    {
        return new self('SOLD_OUT', 'Rất tiếc, đợt này vừa hết slot.', 409);
    }

    public static function alreadyPurchased(): self
    {
        return new self('ALREADY_PURCHASED', 'Bạn đã mua hoặc đang có đơn cho đợt này.', 409);
    }

    public static function notOnSale(): self
    {
        return new self('BATCH_NOT_ON_SALE', 'Đợt này chưa mở bán hoặc đã đóng.', 422);
    }
}
