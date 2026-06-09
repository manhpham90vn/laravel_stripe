<?php

namespace App\Jobs;

use App\Services\StripeEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processes a verified Stripe event off the request path (jobs_and_scheduler
 * §4): the webhook controller verifies the signature, returns 200 fast, and
 * dispatches this job. Retries on transient failures; the processor itself is
 * idempotent (BR-5), so replays never double-grant or double-release.
 */
class ProcessStripeEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int,int> backoff between retries (seconds). */
    public array $backoff = [10, 30, 60, 120];

    /** @param array{id:string,type:string,data:array} $event */
    public function __construct(public array $event) {}

    public function handle(StripeEventProcessor $processor): void
    {
        $processor->process($this->event);
    }
}
