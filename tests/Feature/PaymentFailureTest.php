<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Services\PaymentEventHandler;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Failure / cancellation events and the illegal-transition guard (spec §5.1).
 * The ALLOWED table in PaymentEventHandler is the safety chokepoint, so the
 * impossible jumps must stay impossible even when Stripe sends them.
 */
class PaymentFailureTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function failedEvent(Order $order, string $type = 'payment_intent.payment_failed'): array
    {
        return [
            'id' => 'evt_failed_'.$order->id,
            'type' => $type,
            'data' => ['object' => [
                'id' => 'pi_'.$order->id,
                'metadata' => ['order_id' => (string) $order->id],
                'last_payment_error' => ['message' => 'card declined'],
            ]],
        ];
    }

    /** payment_failed on a pending order → failed + slot released (spec §8.2). */
    public function test_payment_failed_releases_the_slot(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $order = $this->reserve($batch);

        app(StripeEventProcessor::class)->process($this->failedEvent($order));

        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
        $this->assertEquals(Reservation::STATUS_RELEASED, $order->reservation->fresh()->status);
        $batch->refresh();
        $this->assertEquals(0, $batch->slots_taken);
        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->status);
    }

    /** payment_intent.canceled is handled the same as a failure. */
    public function test_payment_intent_canceled_releases_the_slot(): void
    {
        $order = $this->reserve($this->onSaleBatch());

        app(StripeEventProcessor::class)->process($this->failedEvent($order, 'payment_intent.canceled'));

        $this->assertEquals(Order::STATUS_FAILED, $order->fresh()->status);
    }

    /** A late failure after a successful charge must NOT undo a paid order (§5.1). */
    public function test_failure_after_paid_is_blocked(): void
    {
        $order = $this->paidOrder();

        app(StripeEventProcessor::class)->process($this->failedEvent($order));

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE]);
    }

    /** Refunding a pending (never-paid) order is illegal and is dropped. */
    public function test_refund_on_a_non_paid_order_is_blocked(): void
    {
        $order = $this->reserve($this->onSaleBatch());

        app(PaymentEventHandler::class)->markRefunded($order, ['charge_id' => 'ch_x']);

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }

    /** A succeeded webhook arriving on a canceled order cannot resurrect it. */
    public function test_paid_cannot_follow_canceled(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        app(PaymentEventHandler::class)->cancel($order, $order->user_id);
        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);

        app(PaymentEventHandler::class)->markPaid($order->fresh(), ['payment_intent_id' => 'pi_late']);

        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertDatabaseMissing('enrollments', ['order_id' => $order->id]);
    }
}
