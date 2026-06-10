<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "Dấu đã xử lý" cho idempotency webhook (BR-5): mỗi event.id của Stripe được
 * ghi một lần. Khóa chính là CHÍNH event_id (chuỗi, không auto-increment) — nhờ
 * vậy ghi trùng đụng unique violation và bị loại. Không cần timestamps mặc định;
 * chỉ giữ `processed_at`. Dọn định kỳ bởi PruneProcessedStripeEvents.
 */
class ProcessedStripeEvent extends Model
{
    protected $table = 'processed_stripe_events';
    protected $primaryKey = 'event_id';   // khóa chính = event id của Stripe
    public $incrementing = false;         // không tự tăng (là chuỗi)
    protected $keyType = 'string';
    public $timestamps = false;           // chỉ dùng processed_at, bỏ created/updated

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
