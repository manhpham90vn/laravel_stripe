<?php

namespace Tests\Feature;

use App\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * The Checkout Session params (payment_solutions §1.2 / BR-3, BR-8): server-side
 * amount, and a Konbini voucher whose lifetime matches the async slot-hold TTL.
 */
class StripeCheckoutParamsTest extends TestCase
{
    use PaymentFixtures, RefreshDatabase;

    private function gateway(): StripeGateway
    {
        return new StripeGateway('sk_test_x', 'whsec_x');
    }

    /** BR-3: amount/currency come from the order snapshot (JPY zero-decimal). */
    public function test_amount_is_taken_from_the_order(): void
    {
        $order = $this->reserve($this->onSaleBatch(overrides: ['price' => 10000]));

        $params = $this->gateway()->checkoutParams($order);

        $this->assertEquals(10000, $params['line_items'][0]['price_data']['unit_amount']);
        $this->assertEquals('jpy', $params['line_items'][0]['price_data']['currency']);
        $this->assertEquals((string) $order->id, $params['metadata']['order_id']);
    }

    /** BR-8: konbini voucher expiry is pinned to the async TTL when enabled. */
    public function test_konbini_voucher_expiry_matches_async_ttl(): void
    {
        config(['payment.payment_methods' => ['card', 'konbini'], 'payment.ttl.async_days' => 3]);
        $order = $this->reserve($this->onSaleBatch());

        $params = $this->gateway()->checkoutParams($order);

        $this->assertEquals(3, $params['payment_method_options']['konbini']['expires_after_days']);
    }

    /** Card-only checkout doesn't carry konbini options. */
    public function test_card_only_checkout_has_no_konbini_options(): void
    {
        config(['payment.payment_methods' => ['card']]);
        $order = $this->reserve($this->onSaleBatch());

        $params = $this->gateway()->checkoutParams($order);

        $this->assertArrayNotHasKey('payment_method_options', $params);
    }

    /** expires_after_days is clamped to Stripe's 1–60 day bounds. */
    public function test_konbini_expiry_is_clamped_to_stripe_bounds(): void
    {
        config(['payment.payment_methods' => ['konbini'], 'payment.ttl.async_days' => 999]);
        $order = $this->reserve($this->onSaleBatch());

        $params = $this->gateway()->checkoutParams($order);

        $this->assertEquals(60, $params['payment_method_options']['konbini']['expires_after_days']);
    }

    /** §8.2a: the session lifetime is bounded to our configured TTL, not 24h. */
    public function test_session_expiry_matches_configured_ttl(): void
    {
        config(['payment.ttl.session_minutes' => 45]);
        $order = $this->reserve($this->onSaleBatch());

        $this->freezeTime(function () use ($order) {
            $params = $this->gateway()->checkoutParams($order);

            $this->assertEquals(now()->addMinutes(45)->timestamp, $params['expires_at']);
        });
    }

    /** Stripe rejects expires_at under 30 min, so a shorter TTL is clamped up. */
    public function test_session_expiry_is_clamped_to_stripe_minimum(): void
    {
        config(['payment.ttl.session_minutes' => 15]);
        $order = $this->reserve($this->onSaleBatch());

        $this->freezeTime(function () use ($order) {
            $params = $this->gateway()->checkoutParams($order);

            $this->assertEquals(now()->addMinutes(30)->timestamp, $params['expires_at']);
        });
    }
}
