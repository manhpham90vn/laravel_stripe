<?php

namespace Tests\Feature;

use App\Jobs\ReconcileStripeOrders;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * BR-11 / payment_solutions §2.9: the settled figure (amount_received) is what
 * gets reconciled against the order snapshot, not the (possibly larger) PI
 * authorized amount.
 */
class AmountReceivedTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function reconcileWith(Order $order, array $pi): void
    {
        $gateway = $this->mock(PaymentGateway::class);
        $gateway->shouldReceive('retrievePaymentIntentForOrder')->andReturn($pi);

        app(ReconcileStripeOrders::class)->handle($gateway, app(PaymentEventHandler::class));
    }

    /** amount_received matching the snapshot grants even if `amount` differs. */
    public function test_reconcile_grants_on_matching_amount_received(): void
    {
        $order = $this->reserve($this->onSaleBatch(overrides: ['price' => 10000]));
        $order->update(['stripe_payment_intent_id' => 'pi_r', 'created_at' => now()->subHour()]);

        $this->reconcileWith($order, [
            'id' => 'pi_r', 'status' => 'succeeded',
            'amount' => 12000, 'amount_received' => 10000,   // settled = snapshot
            'latest_charge' => 'ch_r', 'payment_method_type' => 'card',
        ]);

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', ['order_id' => $order->id]);
    }

    /** A settled amount that doesn't match must not grant (BR-11). */
    public function test_reconcile_does_not_grant_on_mismatched_amount_received(): void
    {
        $order = $this->reserve($this->onSaleBatch(overrides: ['price' => 10000]));
        $order->update(['stripe_payment_intent_id' => 'pi_r2', 'created_at' => now()->subHour()]);

        $this->reconcileWith($order, [
            'id' => 'pi_r2', 'status' => 'succeeded',
            'amount' => 10000, 'amount_received' => 9000,   // underpaid
            'latest_charge' => 'ch_r2', 'payment_method_type' => 'card',
        ]);

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
        $this->assertDatabaseMissing('enrollments', ['order_id' => $order->id]);
    }
}
