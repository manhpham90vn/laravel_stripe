<?php

namespace App\Exceptions;

use App\Models\Order;
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
    public ?Order $existingOrder = null;

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

    /** Dùng khi đã tìm được đơn live — message phân biệt paid vs chưa hoàn tất. */
    public static function alreadyPurchasedWithOrder(Order $order): self
    {
        $message = $order->status === Order::STATUS_PAID
            ? 'Bạn đã mua đợt này rồi.'
            : 'Bạn đang có đơn chưa hoàn tất cho đợt này. Tiếp tục thanh toán hoặc hủy đơn bên dưới.';

        $e = new self('ALREADY_PURCHASED', $message, 409);
        $e->existingOrder = $order;

        return $e;
    }

    public static function notOnSale(): self
    {
        return new self('BATCH_NOT_ON_SALE', 'Đợt này chưa mở bán hoặc đã đóng.', 422);
    }

    /**
     * Giá > 0 nhưng DƯỚI ngưỡng charge tối thiểu của Stripe theo currency
     * (JPY ~¥50) — không thể tạo charge (issue 2.13). Đây là lỗi cấu hình đợt
     * bán (đặt giá quá nhỏ), không phải lỗi người mua.
     */
    public static function belowMinimumCharge(): self
    {
        return new self('BELOW_MINIMUM_CHARGE', 'Giá đợt bán dưới ngưỡng thanh toán tối thiểu.', 422);
    }
}
