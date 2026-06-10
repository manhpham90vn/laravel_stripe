<?php

namespace App\Jobs;

use App\Services\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Xử lý một event Stripe đã verify, NGOÀI luồng request (jobs_and_scheduler §4):
 * controller webhook verify chữ ký, trả 200 thật nhanh rồi dispatch job này.
 *
 * Job retry khi lỗi tạm thời; bản thân processor là idempotent (BR-5) nên chạy
 * lại không bao giờ cấp quyền / nhả chỗ hai lần.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int,int> thời gian chờ giữa các lần retry (giây). */
    public array $backoff = [10, 30, 60, 120];

    /** @param array{id:string,type:string,data:array} $event */
    public function __construct(public array $event) {}

    public function handle(StripeEventProcessor $processor): void
    {
        $processor->process($this->event);
    }
}
