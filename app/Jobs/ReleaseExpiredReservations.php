<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\PaymentEventHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sweeps orders whose hold has expired (TTL elapsed before payment) and frees
 * their slot (spec §6 Phương án A, §7.3; AC-5). Scheduled every minute.
 *
 * Async orders (processing) carry a long reserved_until = voucher expiry, so
 * they are only swept once that deadline truly passes (BR-8).
 */
class ReleaseExpiredReservations implements ShouldQueue
{
    use Queueable;

    public function handle(PaymentEventHandler $handler): void
    {
        Order::query()
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PROCESSING])
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->orderBy('id')
            ->each(function (Order $order) use ($handler) {
                $handler->expire($order);
            });
    }
}
