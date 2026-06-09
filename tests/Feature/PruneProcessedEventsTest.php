<?php

namespace Tests\Feature;

use App\Jobs\PruneProcessedStripeEvents;
use App\Models\ProcessedStripeEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** payment_solutions §2.8 review #8: old idempotency markers are pruned. */
class PruneProcessedEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_markers_older_than_retention_and_keeps_recent_ones(): void
    {
        config(['payment.processed_events.retention_days' => 60]);

        ProcessedStripeEvent::create(['event_id' => 'evt_old', 'type' => 't', 'processed_at' => now()->subDays(61)]);
        ProcessedStripeEvent::create(['event_id' => 'evt_edge', 'type' => 't', 'processed_at' => now()->subDays(59)]);
        ProcessedStripeEvent::create(['event_id' => 'evt_new', 'type' => 't', 'processed_at' => now()->subDay()]);

        app(PruneProcessedStripeEvents::class)->handle();

        $this->assertDatabaseMissing('processed_stripe_events', ['event_id' => 'evt_old']);
        $this->assertDatabaseHas('processed_stripe_events', ['event_id' => 'evt_edge']);
        $this->assertDatabaseHas('processed_stripe_events', ['event_id' => 'evt_new']);
    }
}
