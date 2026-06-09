<?php

namespace App\Jobs;

use App\Models\SaleBatch;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Drives the time-based parts of the batch state machine (spec §5.3, BR-9):
 *   scheduled → on_sale   when the sale window opens
 *   on_sale   → closed    when sale_ends_at passes
 * (sold_out is driven by slot counters in ReservationService.)
 * Scheduled every minute.
 */
class SyncBatchStatuses implements ShouldQueue
{
    use Queueable;

    public function handle(AuditLogger $audit): void
    {
        $now = now();

        // Open scheduled batches whose window has started.
        SaleBatch::where('status', SaleBatch::STATUS_SCHEDULED)
            ->where('sale_starts_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('sale_ends_at')->orWhere('sale_ends_at', '>', $now))
            ->each(function (SaleBatch $batch) use ($audit) {
                $batch->update(['status' => SaleBatch::STATUS_ON_SALE]);
                $audit->record($batch, SaleBatch::STATUS_SCHEDULED, SaleBatch::STATUS_ON_SALE, 'system');
            });

        // Close batches past their end time (any non-closed status).
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
