<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use App\Services\EnrollmentService;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * The idempotency marker is written FIRST, inside the same transaction as the
 * side-effect (payment_solutions §2.8). These tests pin that contract: a
 * duplicate applies once, and a side-effect that rolls back drops the marker too
 * so Stripe's retry can redo it (no "marked but didn't act").
 */
class WebhookIdempotencyTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    /** BR-5 / AC-3: replaying the same event N times applies exactly once. */
    public function test_duplicate_succeeded_event_applies_exactly_once(): void
    {
        $batch = $this->onSaleBatch(capacity: 5);
        $order = $this->reserve($batch);
        $event = $this->succeededEvent($order);

        $processor = app(StripeEventProcessor::class);
        $processor->process($event);
        $processor->process($event);   // retry
        $processor->process($event);   // retry again

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertEquals(1, Enrollment::where('order_id', $order->id)->count());
        $this->assertEquals(1, $batch->fresh()->slots_taken);
        $this->assertEquals(1, ProcessedStripeEvent::where('event_id', $event['id'])->count());
    }

    /** The marker and the paid/enrollment side-effect commit together. */
    public function test_marker_is_written_atomically_with_the_side_effect(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $event = $this->succeededEvent($order);

        app(StripeEventProcessor::class)->process($event);

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('processed_stripe_events', ['event_id' => $event['id']]);
    }

    /**
     * §2.8: if the side-effect throws, the whole transaction rolls back — the
     * order stays pending AND no marker is left behind, so the retry reprocesses.
     */
    public function test_side_effect_failure_rolls_back_the_marker(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $event = $this->succeededEvent($order);

        // Force the grant to blow up mid-transaction.
        $throwing = Mockery::mock(EnrollmentService::class);
        $throwing->shouldReceive('grant')->andThrow(new RuntimeException('db blip'));
        $this->app->instance(EnrollmentService::class, $throwing);

        try {
            app(StripeEventProcessor::class)->process($event);
            $this->fail('Expected the side-effect failure to propagate so the job retries.');
        } catch (RuntimeException $e) {
            // expected — the queue would retry this event
        }

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertDatabaseMissing('processed_stripe_events', ['event_id' => $event['id']]);
        $this->assertDatabaseMissing('enrollments', ['order_id' => $order->id]);
    }

    /** An event that matches no order is still recorded so it isn't re-warned forever. */
    public function test_unmatched_event_is_recorded(): void
    {
        app(StripeEventProcessor::class)->process([
            'id' => 'evt_orphan',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_nope', 'metadata' => []]],
        ]);

        $this->assertDatabaseHas('processed_stripe_events', ['event_id' => 'evt_orphan']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
