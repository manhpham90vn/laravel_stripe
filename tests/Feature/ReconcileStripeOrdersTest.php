<?php

namespace Tests\Feature;

use App\Jobs\ReconcileStripeOrders;
use App\Models\Enrollment;
use App\Models\Order;
use App\Payments\PaymentGateway;
use App\Services\PaymentEventHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Reconcile job internals not covered by the happy-path reconcile tests:
 * the shallow vs deep window (jobs_and_scheduler §5) and the AC-9 amount-mismatch
 * guard.
 */
class ReconcileStripeOrdersTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function reconcile(bool $deep = false): void
    {
        (new ReconcileStripeOrders(deep: $deep))
            ->handle(app(PaymentGateway::class), app(PaymentEventHandler::class));
    }

    /** The shallow run (every 15 min) ignores orders older than 30 days. */
    public function test_shallow_run_skips_orders_older_than_thirty_days(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_payment_intent_id' => 'pi_old', 'created_at' => now()->subDays(40)]);

        // Out of the shallow window → the gateway must not be queried for it.
        $this->mock(PaymentGateway::class)->shouldReceive('retrievePaymentIntentForOrder')->never();

        $this->reconcile(deep: false);

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }

    /** The deep run (daily) sweeps everything, including the stale tail. */
    public function test_deep_run_settles_an_old_missed_succeeded(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_payment_intent_id' => 'pi_old', 'created_at' => now()->subDays(40)]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('retrievePaymentIntentForOrder')->once()
            ->andReturn([
                'id' => 'pi_old', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'jpy',
                'payment_method_type' => 'card', 'latest_charge' => 'ch_old',
            ]);

        $this->reconcile(deep: true);

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('enrollments', [
            'order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE,
        ]);
    }

    /** An amount that disagrees with the snapshot is logged (AC-9 safety net). */
    public function test_amount_mismatch_is_logged(): void
    {
        $order = $this->reserve($this->onSaleBatch());   // amount snapshot = 10000
        $order->update(['stripe_payment_intent_id' => 'pi_mismatch', 'created_at' => now()->subHour()]);

        // Leave the PI in a non-terminal state so the mismatch warning is the
        // only side effect (status stays pending).
        $this->mock(PaymentGateway::class)
            ->shouldReceive('retrievePaymentIntentForOrder')->once()
            ->andReturn(['id' => 'pi_mismatch', 'status' => 'processing', 'amount' => 99999, 'currency' => 'jpy']);

        Log::spy();

        $this->reconcile();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'amount mismatch'))
            ->once();

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }
}
