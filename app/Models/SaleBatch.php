<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đợt mở bán (sale batch) — một lần bán của course, có `capacity`, cửa sổ thời
 * gian [sale_starts_at, sale_ends_at] và giá riêng. `slots_taken` đếm số chỗ đã
 * chiếm; bất biến 0 ≤ slots_taken ≤ capacity (BR-1) do ReservationService giữ.
 */
class SaleBatch extends Model
{
    protected $guarded = [];

    // State machine (spec §5.3): scheduled → on_sale → (sold_out ↔ on_sale) → closed.
    public const STATUS_SCHEDULED = 'scheduled';   // chưa tới giờ bán
    public const STATUS_ON_SALE = 'on_sale';       // đang bán, còn chỗ
    public const STATUS_SOLD_OUT = 'sold_out';     // hết chỗ (có thể mở lại nếu nhả)
    public const STATUS_CLOSED = 'closed';         // đã đóng (hết giờ / admin đóng)

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

    /** Số chỗ còn lại (không âm). */
    public function remainingSlots(): int
    {
        return max(0, $this->capacity - $this->slots_taken);
    }

    public function isSoldOut(): bool
    {
        return $this->remainingSlots() <= 0;
    }

    /** Bây giờ có nằm trong cửa sổ bán không? (status được job sync riêng.) */
    public function isWithinWindow(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->sale_starts_at <= $at
            && ($this->sale_ends_at === null || $this->sale_ends_at >= $at);
    }

    /** Người mua có thể bắt đầu checkout đợt này ngay lúc này không? */
    public function isPurchasable(): bool
    {
        return $this->status === self::STATUS_ON_SALE
            && ! $this->isSoldOut()
            && $this->isWithinWindow();
    }

    // --- Alias hiển thị cho Blade view (đổi tên cột cho gọn ở template) -----

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
