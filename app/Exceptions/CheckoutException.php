<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Lỗi NGHIỆP VỤ khi checkout (không phải lỗi hệ thống). `errorCode` là định danh
 * nội bộ theo spec §9 (SOLD_OUT / ALREADY_PURCHASED / BATCH_NOT_ON_SALE); message
 * là câu hiển thị cho người mua qua flash. `httpStatus` gợi ý mã HTTP nếu cần.
 *
 * Controller bắt exception này để redirect-back kèm flash (không phải 500).
 * Dùng các factory tĩnh bên dưới để tạo cho thống nhất.
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
