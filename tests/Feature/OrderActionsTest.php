<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/** Buyer-facing order routes: viewing (ownership), canceling, and pay-retry (spec §9, §11). */
class OrderActionsTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    public function test_owner_can_cancel_a_pending_order_and_frees_the_slot(): void
    {
        $batch = $this->onSaleBatch(capacity: 1);
        $user = User::factory()->create();
        $order = $this->reserve($batch, $user);

        $this->actingAs($user)
            ->post(route('orders.cancel', $order->id))
            ->assertRedirect(route('orders.show', $order->id))
            ->assertSessionHas('status');

        $this->assertEquals(Order::STATUS_CANCELED, $order->fresh()->status);
        $this->assertEquals(Reservation::STATUS_RELEASED, $order->reservation->fresh()->status);
        $this->assertEquals(0, $batch->fresh()->slots_taken);
        $this->assertEquals(SaleBatch::STATUS_ON_SALE, $batch->fresh()->status);
    }

    public function test_cancel_on_a_paid_order_is_a_no_op(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($order->user)
            ->post(route('orders.cancel', $order->id))
            ->assertRedirect();

        $this->assertEquals(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_buyer_cannot_view_someone_elses_order(): void
    {
        $order = $this->reserve($this->onSaleBatch(), User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->get(route('orders.show', $order->id))
            ->assertForbidden();
    }

    public function test_a_buyer_cannot_cancel_someone_elses_order(): void
    {
        $order = $this->reserve($this->onSaleBatch(), User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post(route('orders.cancel', $order->id))
            ->assertForbidden();

        $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
    }

    public function test_admin_can_view_any_order(): void
    {
        $order = $this->reserve($this->onSaleBatch(), User::factory()->create());

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('orders.show', $order->id))
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $order = $this->reserve($this->onSaleBatch(), User::factory()->create());

        $this->get(route('orders.show', $order->id))->assertRedirect(route('login'));
    }

    public function test_pay_retry_redirects_a_pending_order_to_stripe(): void
    {
        $user = User::factory()->create();
        $order = $this->reserve($this->onSaleBatch(), $user);

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')->once()
            ->andReturn(new CheckoutSession('https://checkout.stripe.test/retry', 'pi_retry'));

        $this->actingAs($user)
            ->post(route('orders.pay', $order->id))
            ->assertRedirect('https://checkout.stripe.test/retry');
    }

    public function test_pay_retry_is_rejected_for_a_settled_order(): void
    {
        $order = $this->paidOrder();

        $this->actingAs($order->user)
            ->post(route('orders.pay', $order->id))
            ->assertStatus(409);
    }
}
