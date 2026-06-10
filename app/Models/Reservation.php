<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Giữ chỗ (reservation) — Phương án A: một chỗ được giữ TẠM cho 1 user trong khi
 * chờ thanh toán. Vòng đời gắn với `reserved_until` (TTL) và do ReservationService
 * điều khiển. Mỗi (user, batch) tối đa 1 reservation `active` (BR-2).
 */
class Reservation extends Model
{
    protected $guarded = [];

    public const STATUS_ACTIVE = 'active';       // đang giữ chỗ, chờ trả tiền
    public const STATUS_CONSUMED = 'consumed';   // đã trả → chỗ bị chiếm vĩnh viễn
    public const STATUS_EXPIRED = 'expired';     // hết TTL trước khi trả
    public const STATUS_RELEASED = 'released';   // bị hủy / thất bại

    protected $casts = [
        'reserved_until' => 'datetime',
    ];

    public function saleBatch(): BelongsTo
    {
        return $this->belongsTo(SaleBatch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Đã quá hạn giữ chỗ chưa? (so `reserved_until` với thời điểm $at). */
    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->reserved_until < ($at ?? now());
    }
}
