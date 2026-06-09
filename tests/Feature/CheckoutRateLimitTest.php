<?php

namespace Tests\Feature;

use App\Models\User;
use App\Payments\CheckoutSession;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * payment_solutions §1.2 review #7: checkout is rate-limited so one user can't
 * spam "Mua" and hoard inventory.
 */
class CheckoutRateLimitTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    public function test_checkout_is_throttled_per_user(): void
    {
        config(['payment.rate_limit.checkout_per_minute' => 2]);
        RateLimiter::clear('checkout');

        $user = User::factory()->create();
        $batch = $this->onSaleBatch();

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')
            ->andReturn(new CheckoutSession('https://checkout.stripe.test/x', 'pi_x'));

        // First two go through (1st reserves, 2nd hits the existing-order branch).
        $this->actingAs($user)->post(route('checkout.store', $batch->id))->assertRedirect();
        $this->actingAs($user)->post(route('checkout.store', $batch->id))->assertRedirect();

        // Third within the same minute is throttled before reaching the controller.
        $this->actingAs($user)->post(route('checkout.store', $batch->id))->assertStatus(429);
    }

    public function test_separate_users_have_independent_limits(): void
    {
        config(['payment.rate_limit.checkout_per_minute' => 1]);
        RateLimiter::clear('checkout');

        $batch = $this->onSaleBatch();

        $this->mock(PaymentGateway::class)
            ->shouldReceive('createCheckout')
            ->andReturn(new CheckoutSession('https://checkout.stripe.test/x', 'pi_x'));

        $this->actingAs(User::factory()->create())->post(route('checkout.store', $batch->id))->assertRedirect();
        // A different user is not blocked by the first user's spend.
        $this->actingAs(User::factory()->create())->post(route('checkout.store', $batch->id))->assertRedirect();
    }
}
