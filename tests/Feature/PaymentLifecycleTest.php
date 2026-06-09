<?php

namespace Tests\Feature;

use App\Jobs\ReconcileStripeOrders;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use App\Services\ReservationService;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the payment events beyond the happy path: async checkout completion,
 * disputes/chargebacks, and the reconciliation safety net.
 */
class PaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function onSaleBatch(int $capacity = 5): SaleBatch
    {
        $course = Course::create([
            'title' => 'Test Course', 'slug' => 'test-course-'.uniqid(),
            'summary' => 's', 'description' => 'd', 'status' => 'published',
        ]);

        return $course->batches()->create([
            'name' => 'Đợt test', 'capacity' => $capacity, 'slots_taken' => 0,
            'price' => 10000, 'currency' => 'JPY',
            'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay(),
            'status' => SaleBatch::STATUS_ON_SALE,
        ]);
    }

    private function paidOrder(): Order
    {
        $order = app(ReservationService::class)->reserve($this->onSaleBatch(), User::factory()->create());

        app(StripeEventProcessor::class)->process([
            'id' => 'evt_paid_'.$order->id,
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_'.$order->id, 'payment_method_type' => 'card',
                'latest_charge' => 'ch_'.$order->id, 'metadata' => ['order_id' => (string) $order->id],
            ]],
        ]);

        return $order->fresh();
    }

    /** payment_intent.succeeded stores the real PI id over the session placeholder (§6 fix). */
    public function test_succeeded_records_real_payment_intent_id(): void
    {
        $order = $this->paidOrder();

        $this->assertEquals('pi_'.$order->id, $order->stripe_payment_intent_id);
        $this->assertEquals('ch_'.$order->id, $order->stripe_charge_id);
    }

    /** Async checkout: session completed (unpaid) → processing + slot held (§8.2). */
    public function test_checkout_session_completed_async_moves_to_processing(): void
    {
        $order = app(ReservationService::class)->reserve($this->onSaleBatch(), User::factory()->create());

        app(StripeEventProcessor::class)->process([
            'id' => 'evt_cs_1',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test', 'payment_intent' => 'pi_async_1',
                'payment_status' => 'unpaid', 'payment_method_types' => ['konbini'],
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ]);

        $order->refresh();
        $this->assertEquals(Order::STATUS_PROCESSING, $order->status);
        $this->assertEquals('pi_async_1', $order->stripe_payment_intent_id);
    }

    /** Dispute opened keeps access; closed-lost refunds and revokes (§5.1, §8.2). */
    public function test_dispute_lost_revokes_enrollment(): void
    {
        $order = $this->paidOrder();
        $processor = app(StripeEventProcessor::class);

        $processor->process([
            'id' => 'evt_dispute_open',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['id' => 'dp_1', 'charge' => $order->stripe_charge_id, 'status' => 'needs_response']],
        ]);

        $this->assertEquals(Order::STATUS_DISPUTED, $order->fresh()->status);
        // Access stays until the dispute resolves.
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE]);

        $processor->process([
            'id' => 'evt_dispute_closed',
            'type' => 'charge.dispute.closed',
            'data' => ['object' => ['id' => 'dp_1', 'charge' => $order->stripe_charge_id, 'status' => 'lost']],
        ]);

        $this->assertEquals(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_REVOKED]);
    }

    /** Dispute won returns the order to paid and keeps access (§5.1). */
    public function test_dispute_won_restores_paid(): void
    {
        $order = $this->paidOrder();
        $processor = app(StripeEventProcessor::class);

        $processor->process([
            'id' => 'evt_dispute_open_2',
            'type' => 'charge.dispute.created',
            'data' => ['object' => ['id' => 'dp_2', 'payment_intent' => $order->stripe_payment_intent_id, 'status' => 'needs_response']],
        ]);
        $processor->process([
            'id' => 'evt_dispute_won_2',
            'type' => 'charge.dispute.closed',
            'data' => ['object' => ['id' => 'dp_2', 'payment_intent' => $order->stripe_payment_intent_id, 'status' => 'won']],
        ]);

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE]);
    }

    /** Reconcile grants the enrollment when the success webhook was missed (§5). */
    public function test_reconcile_settles_a_missed_succeeded_webhook(): void
    {
        $order = app(ReservationService::class)->reserve($this->onSaleBatch(), User::factory()->create());
        $order->update(['stripe_payment_intent_id' => 'pi_recon_1', 'created_at' => now()->subHour()]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('retrievePaymentIntentForOrder')
            ->once()
            ->andReturn([
                'id' => 'pi_recon_1', 'status' => 'succeeded', 'amount' => 10000,
                'currency' => 'jpy', 'payment_method_type' => 'card', 'latest_charge' => 'ch_recon_1',
            ]);

        app(ReconcileStripeOrders::class)->handle(
            app(PaymentGateway::class),
            app(PaymentEventHandler::class),
        );

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE]);
    }
}
