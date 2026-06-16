<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Đơn hàng — một giao dịch gắn (1 đợt bán + 1 user), ánh xạ tới 1 PaymentIntent
 * của Stripe. State machine ở spec §5.1, thực thi bởi PaymentEventHandler.
 * `amount` là snapshot giá lúc checkout (BR-3, không đổi khi admin sửa giá sau).
 */
class Order extends Model
{
    protected $guarded = [];

    // Các trạng thái đơn (spec §5.1).
    public const STATUS_PENDING = 'pending';        // vừa tạo, chờ trả (đồng bộ)
    public const STATUS_PROCESSING = 'processing';  // async — đã đặt voucher, chờ tiền
    public const STATUS_PAID = 'paid';              // tiền đã về, đã cấp enrollment
    public const STATUS_FAILED = 'failed';          // thanh toán thất bại
    public const STATUS_CANCELED = 'canceled';      // hủy / hết hạn chưa trả
    public const STATUS_REFUNDED = 'refunded';      // đã hoàn tiền
    public const STATUS_DISPUTED = 'disputed';      // đang khiếu nại (chargeback)

    /** Các trạng thái đang GIỮ chỗ thật sự (BR-2) — dùng để check 1-đơn-live/đợt. */
    public const LIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_PAID];

    protected $casts = [
        'reserved_until' => 'datetime',
        'paid_at' => 'datetime',
    ];

    private const METHOD_LABELS = [
        'card' => 'Thẻ tín dụng',
        'konbini' => 'Konbini',
        'pay_easy' => 'Pay-easy',
        'bank_transfer' => 'Chuyển khoản',
    ];

    public function saleBatch(): BelongsTo
    {
        return $this->belongsTo(SaleBatch::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollment(): HasOne
    {
        return $this->hasOne(Enrollment::class);
    }

    // --- Presentation aliases used by orders/show.blade.php ---------------

    protected function courseTitle(): Attribute
    {
        return Attribute::get(fn () => $this->saleBatch?->course?->title);
    }

    protected function batchName(): Attribute
    {
        return Attribute::get(fn () => $this->saleBatch?->name);
    }

    protected function batchId(): Attribute
    {
        return Attribute::get(fn () => $this->sale_batch_id);
    }

    protected function method(): Attribute
    {
        return Attribute::get(fn () => self::METHOD_LABELS[$this->payment_method_type] ?? '—');
    }

    protected function dueAt(): Attribute
    {
        return Attribute::get(fn () => $this->reserved_until?->format('d/m H:i'));
    }

    /** Đơn có thể retry thanh toán không (pending hoặc processing). */
    public function isRetriable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true);
    }

    /** Đơn còn "sống" của user cho một đợt bán (BR-2), nếu có. */
    public static function liveFor(SaleBatch $batch, User $user): ?self
    {
        return static::where('sale_batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->whereIn('status', self::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }
}
