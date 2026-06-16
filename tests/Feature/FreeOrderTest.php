<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Order;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Issue 2.13 — amount handling at the boundaries:
 *   • amount == 0 (free course / coupon to ¥0) → fulfil WITHOUT Stripe.
 *   • 0 < amount < Stripe minimum (JPY ~¥50) → reject (can't create a charge).
 */
class FreeOrderTest extends TestCase
{
    use PaymentFixtures;
    use RefreshDatabase;

    public function test_free_batch_is_fulfilled_without_calling_stripe(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch(overrides: ['price' => 0]);

        // A free order must never reach the payment gateway.
        $this->mock(PaymentGateway::class)->shouldNotReceive('createCheckout');

        $response = $this->actingAs($user)->post(route('checkout.store', $batch->id));

        $order = Order::firstOrFail();
        $response->assertRedirect(route('orders.show', $order->id));

        // Goes straight to paid via the same "one door to paid" (markPaid).
        $this->assertEquals(Order::STATUS_PAID, $order->status);
        $this->assertEquals('free', $order->payment_method_type);
        $this->assertNull($order->stripe_charge_id);
        $this->assertNull($order->stripe_payment_intent_id);
        $this->assertNotNull($order->paid_at);

        // Slot consumed + enrollment granted, exactly like a paid card order.
        $this->assertEquals(1, $batch->fresh()->slots_taken);
        $this->assertDatabaseHas('enrollments', [
            'order_id' => $order->id, 'status' => Enrollment::STATUS_ACTIVE,
        ]);
    }

    public function test_price_below_stripe_minimum_is_rejected_and_holds_no_slot(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch(overrides: ['price' => 10]); // ¥10 < ¥50 minimum

        $this->mock(PaymentGateway::class)->shouldNotReceive('createCheckout');

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id))
            ->assertRedirect(route('batches.show', $batch->id))
            ->assertSessionHas('error');

        // Rejected before reserving — no dangling order, no held slot.
        $this->assertDatabaseCount('orders', 0);
        $this->assertEquals(0, $batch->fresh()->slots_taken);
    }

    /** Exactly at the ¥50 floor a real charge is created (gateway is used). */
    public function test_price_at_the_minimum_goes_through_stripe(): void
    {
        $user = User::factory()->create();
        $batch = $this->onSaleBatch(overrides: ['price' => 50]);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')->once()
            ->andReturn(new \App\Payments\CheckoutSession('https://checkout.stripe.test/x', 'pi_x'));

        $this->actingAs($user)
            ->post(route('checkout.store', $batch->id))
            ->assertRedirect('https://checkout.stripe.test/x');

        $this->assertEquals(Order::STATUS_PENDING, Order::firstOrFail()->status);
    }
}
