<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Services\StripeEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Issue 2.5 — `checkout.session.expired` is a trustworthy Stripe signal that the
 * buyer will not pay through that session. The handler must cancel the pending
 * order and release the held slot immediately (not wait for the TTL job).
 */
class CheckoutSessionExpiredTest extends TestCase
{
    use PaymentFixtures;
    use RefreshDatabase;

    private function expiredEvent(Order $order, string $id = 'evt_expired_'): array
    {
        return [
            'id' => $id === 'evt_expired_' ? $id.$order->id : $id,
            'type' => 'checkout.session.expired',
            'data' => ['object' => [
                'id' => 'cs_'.$order->id,
                'payment_intent' => null,
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
    }

    public function test_expired_session_cancels_pending_order_and_releases_slot(): void
    {
        $batch = $this->onSaleBatch(capacity: 3);
        $order = $this->reserve($batch);

        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(1, $batch->fresh()->slots_taken);

        app(StripeEventProcessor::class)->process($this->expiredEvent($order));

        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertEquals(Reservation::STATUS_RELEASED, $order->reservation->fresh()->status);
        $this->assertEquals(0, $batch->fresh()->slots_taken);
    }

    public function test_expired_reopens_a_sold_out_batch(): void
    {
        // Last seat taken → batch flips to sold_out on reserve.
        $batch = $this->onSaleBatch(capacity: 1);
        $order = $this->reserve($batch);
        $this->assertEquals(SaleBatch::STATUS_SOLD_OUT, $batch->fresh()->status);

        app(StripeEventProcessor::class)->process($this->expiredEvent($order));

        // Releasing the slot within the sale window reopens the batch (AC-5).
        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->fresh()->status);
    }

    public function test_expired_is_a_no_op_on_a_paid_order(): void
    {
        // A late `expired` (e.g. the buyer paid card just before expiry) must not
        // undo a paid order — the ALLOWED table blocks paid → canceled.
        $order = $this->paidOrder();

        app(StripeEventProcessor::class)->process($this->expiredEvent($order, 'evt_expired_late'));

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_duplicate_expired_event_applies_once(): void
    {
        $order = $this->reserve($this->onSaleBatch());

        $event = $this->expiredEvent($order);
        $processor = app(StripeEventProcessor::class);
        $processor->process($event);
        $processor->process($event); // redelivery — must be a no-op

        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertDatabaseHas('processed_stripe_events', ['event_id' => $event['id']]);
    }
}
