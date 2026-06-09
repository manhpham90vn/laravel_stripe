<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    protected $guarded = [];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $casts = [
        'granted_at' => 'datetime',
        'access_expires_at' => 'datetime',
    ];

    public function saleBatch(): BelongsTo
    {
        return $this->belongsTo(SaleBatch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // --- Presentation aliases used by my/courses.blade.php ----------------

    protected function title(): Attribute
    {
        return Attribute::get(fn () => $this->course?->title);
    }

    protected function coverFrom(): Attribute
    {
        return Attribute::get(fn () => $this->course?->cover_from);
    }

    protected function coverTo(): Attribute
    {
        return Attribute::get(fn () => $this->course?->cover_to);
    }
}
