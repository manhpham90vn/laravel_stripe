<?php

namespace App\Jobs;

use App\Models\SaleBatch;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Lái các phần THEO THỜI GIAN của state machine sale_batch (spec §5.3, BR-9):
 *   scheduled → on_sale   khi cửa sổ bán mở (tới sale_starts_at)
 *   on_sale   → closed    khi qua sale_ends_at
 *
 * Lưu ý: `sold_out` KHÔNG do job này điều khiển — nó do bộ đếm slot trong
 * ReservationService lái (theo số lượng). Job này chỉ lo TRỤC THỜI GIAN.
 * Chạy theo lịch mỗi phút.
 */
class SyncBatchStatuses implements ShouldQueue
{
    use Queueable;

    public function handle(AuditLogger $audit): void
    {
        $now = now();

        // Mở các batch đã lên lịch mà cửa sổ bán vừa bắt đầu.
        SaleBatch::where('status', SaleBatch::STATUS_SCHEDULED)
            ->where('sale_starts_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>', $now))
            ->each(function (SaleBatch $batch) use ($audit) {
                $batch->update(['status' => SaleBatch::STATUS_ON_SALE]);
                $audit->record($batch, SaleBatch::STATUS_SCHEDULED, SaleBatch::STATUS_ON_SALE, 'system');
            });

        // Đóng các batch đã qua giờ kết thúc (mọi status chưa closed).
        SaleBatch::whereIn('status', [SaleBatch::STATUS_SCHEDULED, SaleBatch::STATUS_ON_SALE, SaleBatch::STATUS_SOLD_OUT])
            ->whereNotNull('sale_ends_at')
            ->where('sale_ends_at', '<', $now)
            ->each(function (SaleBatch $batch) use ($audit) {
                $from = $batch->status;
                $batch->update(['status' => SaleBatch::STATUS_CLOSED]);
                $audit->record($batch, $from, SaleBatch::STATUS_CLOSED, 'system');
            });
    }
}
