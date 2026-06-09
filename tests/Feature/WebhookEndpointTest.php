<?php

namespace Tests\Feature;

use App\Jobs\ProcessStripeEvent;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The webhook endpoint itself (spec §8.1, jobs_and_scheduler §4): verify the
 * signature, return 200 fast, hand processing to a queued job. No auth/CSRF.
 */
class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_signature_acks_200_and_queues_processing(): void
    {
        Queue::fake();

        $event = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded', 'data' => ['object' => []]];
        $this->mock(PaymentGateway::class)
            ->shouldReceive('constructEvent')->once()->andReturn($event);

        $this->postJson('/webhooks/stripe', [], ['Stripe-Signature' => 'sig'])
            ->assertOk();

        Queue::assertPushed(ProcessStripeEvent::class, fn ($job) => $job->event['id'] === 'evt_1');
    }

    public function test_invalid_signature_is_rejected_with_400_and_nothing_queued(): void
    {
        Queue::fake();

        $this->mock(PaymentGateway::class)
            ->shouldReceive('constructEvent')->once()->andThrow(new \RuntimeException('bad sig'));

        $this->postJson('/webhooks/stripe', [], ['Stripe-Signature' => 'nope'])
            ->assertStatus(400);

        Queue::assertNothingPushed();
    }

    public function test_webhook_route_is_exempt_from_auth_and_csrf(): void
    {
        // No auth middleware, no CSRF token — a raw POST still reaches the controller.
        $this->mock(PaymentGateway::class)
            ->shouldReceive('constructEvent')->andReturn(['id' => 'evt_x', 'type' => 't', 'data' => ['object' => []]]);

        $this->post('/webhooks/stripe', [], ['Stripe-Signature' => 'sig'])->assertOk();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
