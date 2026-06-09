<?php

namespace App\Jobs;

use App\Models\ProcessedStripeEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Housekeeping for processed_stripe_events (payment_solutions §2.8 review #8):
 * the idempotency markers grow without bound. Stripe stops retrying an event
 * after a few days, so markers older than the retention window can be deleted
 * without ever re-processing a duplicate. Scheduled daily.
 */
class PruneProcessedStripeEvents implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $days = max(1, (int) config('payment.processed_events.retention_days'));

        $deleted = ProcessedStripeEvent::where('processed_at', '<', now()->subDays($days))->delete();

        if ($deleted > 0) {
            Log::info('Pruned processed Stripe events', ['deleted' => $deleted, 'older_than_days' => $days]);
        }
    }
}
