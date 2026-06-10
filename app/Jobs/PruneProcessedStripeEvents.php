<?php

namespace App\Jobs;

use App\Models\ProcessedStripeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dọn dẹp bảng processed_stripe_events (payment_solutions §2.8 review #8): các
 * "dấu đã xử lý" (idempotency marker) cứ tăng mãi không giới hạn. Vì Stripe ngừng
 * retry một event sau vài ngày, nên dấu cũ hơn cửa sổ giữ (retention_days) có thể
 * XÓA mà không bao giờ làm xử lý lại bản trùng. Chạy theo lịch hằng ngày.
 */
class PruneProcessedStripeEvents implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // Tối thiểu giữ 1 ngày để không bao giờ xóa dấu vừa ghi.
        $days = max(1, (int) config('payment.processed_events.retention_days'));

        $deleted = ProcessedStripeEvent::where('processed_at', '<', now()->subDays($days))->delete();

        if ($deleted > 0) {
            Log::info('Pruned processed Stripe events', ['deleted' => $deleted, 'older_than_days' => $days]);
        }
    }
}
