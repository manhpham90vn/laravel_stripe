<?php

namespace Tests\Feature;

use App\Jobs\ReconcileStripeOrders;
use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Async (Konbini/Pay-easy) full lifecycle — AC-6, spec §7.2, §8.2. */
class AsyncPaymentTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function processingOrder(SaleBatch $batch): Order
    {
        $order = $this->reserve($batch);

        app(StripeEventProcessor::class)->process([
            'id' => 'evt_processing_'.$order->id,
            'type' => 'payment_intent.processing',
            'data' => ['object' => [
                'id' => 'pi_'.$order->id, 'payment_method_type' => 'konbini',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ]);

        return $order->fresh();
    }

    public function test_processing_holds_the_slot_until_voucher_expiry(): void
    {
        $batch = $this->onSaleBatch();
        $order = $this->processingOrder($batch);

        $this->assertEquals(Order::STATUS_PROCESSING, $order->status);
        // The hold is pushed out to the async voucher window (BR-8), not the card TTL.
        $this->assertTrue($order->reserved_until->gt(now()->addDay()));
        $this->assertEquals(1, $batch->fresh()->slots_taken);
    }

    public function test_processing_then_succeeded_grants_enrollment(): void
    {
        $batch = $this->onSaleBatch();
        $order = $this->processingOrder($batch);

        app(StripeEventProcessor::class)->process($this->succeededEvent($order));

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE]);
    }

    public function test_processing_then_voucher_expired_fails_and_releases_slot(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $order = $this->processingOrder($batch);

        app(StripeEventProcessor::class)->process([
            'id' => 'evt_async_failed_'.$order->id,
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_'.$order->id, 'metadata' => ['order_id' => (string) $order->id]]],
        ]);

        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertEquals(Reservation::STATUS_RELEASED, $order->reservation->fresh()->status);
        $this->assertEquals(0, $batch->fresh()->slots_taken);
        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->fresh()->status);
    }

    /** Reconcile converges a canceled PaymentIntent when the webhook was missed (§5A). */
    public function test_reconcile_fails_a_canceled_payment_intent(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_payment_intent_id' => 'pi_recon_cancel', 'created_at' => now()->subHour()]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('retrievePaymentIntentForOrder')->once()
            ->andReturn(['id' => 'pi_recon_cancel', 'status' => 'canceled', 'amount' => 10000, 'currency' => 'jpy']);

        app(ReconcileStripeOrders::class)->handle(app(PaymentGateway::class), app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertEquals(0, $order->saleBatch->fresh()->slots_taken);
    }

    /** A still-processing PaymentIntent is left untouched by reconcile (async still in flight). */
    public function test_reconcile_leaves_a_processing_payment_intent_alone(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_payment_intent_id' => 'pi_recon_proc', 'created_at' => now()->subHour()]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('retrievePaymentIntentForOrder')->once()
            ->andReturn(['id' => 'pi_recon_proc', 'status' => 'processing', 'amount' => 10000, 'currency' => 'jpy']);

        app(ReconcileStripeOrders::class)->handle(app(PaymentGateway::class), app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }
}
