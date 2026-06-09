<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** The checkout HTTP surface (spec §7, §9) — complements the service-level CheckoutTest. */
class CheckoutHttpTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    public function test_guest_cannot_checkout(): void
    {
        $batch = $this->onSaleBatch();

        $this->post(route('checkout.store', $batch->id))->assertRedirect(route('login'));
        $this->assertDatabaseCount('orders', 0);
    }

    /** AC-9 / BR-3: the amount is the server-side batch price, never the client's. */
    public function test_amount_is_snapshotted_from_the_batch_ignoring_client_input(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch(overrides: ['price' => 10000]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')->once()
            ->andReturn(new CheckoutSession('https://checkout.stripe.test/x', 'pi_x'));

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id), ['amount' => 1])  // tampered amount
            ->assertRedirect('https://checkout.stripe.test/x');

        $order = Order::firstOrFail();
        $this->assertEquals(10000, $order->amount);
        $this->assertEquals('JPY', $order->currency);
    }

    /** Sold-out at checkout → back to the batch with the SOLD_OUT flash (spec §7.3). */
    public function test_sold_out_redirects_back_with_error(): void
    {
        $batch = $this->onSaleBatch(capacity: 1, taken: 1);
        $batch->update(['status' => SaleBatch::STATUS_SOLD_OUT]);

        $this->actingAs(User::factory()->create())
            ->post(route('checkout.store', $batch->id))
            ->assertRedirect(route('batches.show', $batch->id))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
    }

    /** BR-2: a second buy attempt routes the buyer to their existing live order. */
    public function test_double_buy_redirects_to_the_existing_order(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();
        $existing = $this->reserve($batch, $user);

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id))
            ->assertRedirect(route('orders.show', $existing->id))
            ->assertSessionHas('status');

        $this->assertEquals(1, Order::where('user_id', $user->id)->count());
    }

    /** If Stripe session creation throws, the slot stays held and the buyer can retry. */
    public function test_gateway_failure_keeps_order_pending_and_flashes_error(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch();

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')->once()
            ->andThrow(new \RuntimeException('stripe down'));

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id))
            ->assertSessionHas('error');

        $order = Order::firstOrFail();
        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(1, $batch->fresh()->slots_taken);
    }
}
