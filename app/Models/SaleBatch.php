<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleBatch extends Model
{
    protected $guarded = [];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ON_SALE = 'on_sale';
    public const STATUS_SOLD_OUT = 'sold_out';
    public const STATUS_CLOSED = 'closed';

    protected $casts = [
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function remainingSlots(): int
    {
        return max(0, $this->capacity - $this->slots_taken);
    }

    public function isSoldOut(): bool
    {
        return $this->remainingSlots() <= 0;
    }

    /** Within the sale window right now? (Status is synced separately by a job.) */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->sale_starts_at <= $at
            && ($this->sale_ends_at === null || $this->sale_ends_at >= $at);
    }

    /** Can a buyer start checkout on this batch right now? */
    public function isPurchasable(): bool
    {
        return $this->status === self::STATUS_ON_SALE
            && ! $this->isSoldOut()
            && $this->isWithinWindow();
    }

    // --- Presentation aliases used by the Blade views ---------------------

    protected function taken(): Attribute
    {
        return Attribute::get(fn () => $this->slots_taken);
    }

    protected function startsAt(): Attribute
    {
        return Attribute::get(fn () => $this->sale_starts_at?->format('d/m'));
    }

    protected function endsAt(): Attribute
    {
        return Attribute::get(fn () => $this->sale_ends_at?->format('d/m'));
    }

    protected function courseTitle(): Attribute
    {
        return Attribute::get(fn () => $this->course?->title);
    }

    protected function courseSlug(): Attribute
    {
        return Attribute::get(fn () => $this->course?->slug);
    }
}
