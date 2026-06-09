<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = [];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PAID = 'paid';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_DISPUTED = 'disputed';

    /** Statuses that hold a live claim on a slot (BR-2). */
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
}
