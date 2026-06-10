<?php

use App\Jobs\PruneProcessedStripeEvents;
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
| Tác vụ nền theo lịch (spec D8 / jobs_and_scheduler.md)
|----------------------------------------------------------------------------
| Chạy scheduler: php artisan schedule:work
| Chạy queue:     php artisan queue:work
| `withoutOverlapping()` để một job không chồng lên lần chạy trước.
*/
// Mỗi phút: nhả chỗ hết TTL, và sync trạng thái đợt theo thời gian.
Schedule::job(new ReleaseExpiredReservations)->everyMinute()->withoutOverlapping();
Schedule::job(new SyncBatchStatuses)->everyMinute()->withoutOverlapping();

// Lưới đỡ cho webhook Stripe bị mất/đến trễ (jobs_and_scheduler §5).
// shallow mỗi 15' (đơn live gần đây); deep hằng ngày (quét cả đơn đã chết).
Schedule::job(new ReconcileStripeOrders)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ReconcileStripeOrders(deep: true))->dailyAt('03:00')->withoutOverlapping();

// Dọn dấu idempotency webhook cũ (payment_solutions §2.8 review #8).
Schedule::job(new PruneProcessedStripeEvents)->dailyAt('04:00')->withoutOverlapping();
