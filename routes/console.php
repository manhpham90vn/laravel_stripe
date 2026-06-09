<?php

use App\Jobs\ReconcileStripeOrders;
use App\Jobs\ReleaseExpiredReservations;
use App\Jobs\SyncBatchStatuses;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|----------------------------------------------------------------------------
| Scheduled background work (spec D8 / jobs_and_scheduler.md)
|----------------------------------------------------------------------------
| Run the scheduler with: php artisan schedule:work
| Process the queue with:  php artisan queue:work
*/
Schedule::job(new ReleaseExpiredReservations)->everyMinute()->withoutOverlapping();
Schedule::job(new SyncBatchStatuses)->everyMinute()->withoutOverlapping();

// Safety net for missed/late Stripe webhooks (jobs_and_scheduler §5).
Schedule::job(new ReconcileStripeOrders)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ReconcileStripeOrders(deep: true))->dailyAt('03:00')->withoutOverlapping();
