<?php

namespace Tests\Feature;

use App\Jobs\ReleaseExpiredReservations;
use App\Jobs\SyncBatchStatuses;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Payments\PaymentGateway;
use App\Services\AuditLogger;
use App\Services\PaymentEventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** The scheduled commands from jobs_and_scheduler.md §2 and §3. */
class ScheduledJobsTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    /** AC-5: an abandoned pending order past its TTL frees the slot and reopens the batch. */
    public function test_release_expired_cancels_pending_and_reopens_sold_out_batch(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $order = $this->reserve($batch);

        $this->assertEquals(SaleBatch::STATUS_SOLD_OUT, $batch->fresh()->status);

        // Push the hold into the past (as the TTL job would find it).
        $order->update(['reserved_until' => now()->subMinute()]);
        $order->reservation->update(['reserved_until' => now()->subMinute()]);

        app(ReleaseExpiredReservations::class)->handle(app(PaymentGateway::class), app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertEquals(Reservation::STATUS_RELEASED, $order->reservation->fresh()->status);
        $batch->refresh();
        $this->assertEquals(0, $batch->slots_taken);
        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->status);
    }

    /** Async holds (long reserved_until) and paid orders are left alone (BR-8). */
    public function test_release_expired_leaves_unexpired_and_paid_orders(): void
    {
        $future = $this->reserve($this->onSaleBatch());          // reserved_until in the future
        $paid = $this->paidOrder();                              // paid, slot kept permanently

        app(ReleaseExpiredReservations::class)->handle(app(PaymentGateway::class), app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_PENDING, $future->fresh()->status);
        $this->assertEquals(Order::STATUS_PAID, $paid->fresh()->status);
    }

    /** §1.3: an expired hold whose PaymentIntent already succeeded converges to paid, not canceled. */
    public function test_release_converges_to_paid_when_payment_already_succeeded(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $order = $this->reserve($batch);
        $order->update(['stripe_payment_intent_id' => 'pi_'.$order->id, 'reserved_until' => now()->subMinute()]);
        $order->reservation->update(['reserved_until' => now()->subMinute()]);

        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('retrievePaymentIntentForOrder')->once()->andReturn([
            'id' => 'pi_'.$order->id, 'status' => 'succeeded', 'amount' => 10000,
            'latest_charge' => 'ch_'.$order->id, 'payment_method_type' => 'card',
        ]);

        app(ReleaseExpiredReservations::class)->handle($gateway, app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertEquals(1, $batch->fresh()->slots_taken);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id]);
    }

    /** §1.3: a payment still in flight (3DS/voucher) is given more time, not released. */
    public function test_release_extends_hold_when_payment_in_flight(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_payment_intent_id' => 'pi_'.$order->id, 'reserved_until' => now()->subMinute()]);

        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('retrievePaymentIntentForOrder')->once()->andReturn([
            'id' => 'pi_'.$order->id, 'status' => 'requires_action',
        ]);

        app(ReleaseExpiredReservations::class)->handle($gateway, app(PaymentEventHandler::class));

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertTrue($order->fresh()->reserved_until->isFuture());
    }

    public function test_sync_opens_scheduled_batches_whose_window_started(): void
    {
        $batch = $this->onSaleBatch(overrides: [
            'status' => SaleBatch::STATUS_SCHEDULED,
            'sale_starts_at' => now()->subMinute(),
            'sale_ends_at' => now()->addDay(),
        ]);

        app(SyncBatchStatuses::class)->handle(app(AuditLogger::class));

        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => 'sale_batches', 'subject_id' => $batch->id,
            'to_status' => SaleBatch::STATUS_ON_SALE, 'actor' => 'system',
        ]);
    }

    public function test_sync_does_not_open_a_batch_before_its_start(): void
    {
        $batch = $this->onSaleBatch(overrides: [
            'status' => SaleBatch::STATUS_SCHEDULED,
            'sale_starts_at' => now()->addDay(),
        ]);

        app(SyncBatchStatuses::class)->handle(app(AuditLogger::class));

        $this->assertEquals(SaleBatch::STATUS_SCHEDULED, $batch->fresh()->status);
    }

    public function test_sync_closes_batches_past_their_end_time(): void
    {
        $onSale = $this->onSaleBatch(overrides: ['sale_starts_at' => now()->subDays(2), 'sale_ends_at' => now()->subMinute()]);
        $soldOut = $this->onSaleBatch(overrides: [
            'status' => SaleBatch::STATUS_SOLD_OUT,
            'sale_starts_at' => now()->subDays(2), 'sale_ends_at' => now()->subMinute(),
        ]);

        app(SyncBatchStatuses::class)->handle(app(AuditLogger::class));

        $this->assertEquals(SaleBatch::STATUS_CLOSED, $onSale->fresh()->status);
        $this->assertEquals(SaleBatch::STATUS_CLOSED, $soldOut->fresh()->status);
    }
}
