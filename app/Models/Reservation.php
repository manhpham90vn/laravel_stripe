<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    protected $guarded = [];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONSUMED = 'consumed';   // paid → slot kept permanently
    public const STATUS_EXPIRED = 'expired';     // TTL elapsed before payment
    public const STATUS_RELEASED = 'released';   // canceled / failed

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

    public function isExpired(?\DateTimeInterface $at = null): bool
    {
        return $this->reserved_until < ($at ?? now());
    }
}
