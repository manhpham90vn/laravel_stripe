<?php

namespace App\Payments;

use App\Models\Order;
use RuntimeException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe via Checkout hosted (D7). Configure STRIPE_SECRET / STRIPE_WEBHOOK_SECRET
 * (use test-mode keys for development).
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(
        private string $secret,
        private ?string $webhookSecret,
    ) {}

    private function client(): StripeClient
    {
        return new StripeClient($this->secret);
    }

    public function createCheckout(Order $order): CheckoutSession
    {
        $order->loadMissing('saleBatch.course');

        $session = $this->client()->checkout->sessions->create($this->checkoutParams($order), [
            'idempotency_key' => $this->idempotencyKey('checkout', $order),   // spec §8.1
        ]);

        $order->update(['stripe_payment_intent_id' => $session->payment_intent ?? $session->id]);

        return new CheckoutSession($session->url, $session->id);
    }

    /**
     * Build the Checkout Session params. Extracted from createCheckout so the
     * money/method/voucher rules can be asserted without hitting Stripe.
     *
     * @return array<string,mixed>
     */
    public function checkoutParams(Order $order): array
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'unit_amount' => (int) $order->amount,   // JPY zero-decimal
                    'product_data' => ['name' => $order->saleBatch->course->title.' — '.$order->saleBatch->name],
                ],
            ]],
            'success_url' => route('orders.show', $order),
            'cancel_url' => route('batches.show', $order->sale_batch_id),
            'payment_intent_data' => [
                'metadata' => $this->metadata($order),
            ],
            'metadata' => $this->metadata($order),
        ];

        // Restrict to configured methods (default: card). Leave empty to let
        // Stripe show every method enabled in the Dashboard for this currency.
        $methods = config('payment.payment_methods');
        if (! empty($methods)) {
            $params['payment_method_types'] = $methods;
        }

        // Konbini: pin the voucher's lifetime to our async slot-hold TTL so the
        // seat is held for exactly as long as the customer has to pay, no longer
        // (payment_solutions §1.2 / spec BR-8). Without this the voucher uses
        // Stripe's default (~3 days) which can drift from reserved_until.
        if (empty($methods) || in_array('konbini', $methods, true)) {
            $days = max(1, min(60, (int) config('payment.ttl.async_days')));
            $params['payment_method_options']['konbini']['expires_after_days'] = $days;
        }

        return $params;
    }

    public function refund(Order $order): void
    {
        if (! $order->stripe_charge_id) {
            throw new RuntimeException("Order {$order->id} has no charge to refund.");
        }

        $this->client()->refunds->create([
            'charge' => $order->stripe_charge_id,
            'metadata' => $this->metadata($order),
        ], [
            'idempotency_key' => $this->idempotencyKey('refund', $order),
        ]);
    }

    /**
     * Idempotency key bound to the order *instance*, not just its primary key.
     * Auto-increment ids are reused after a DB reset (migrate:fresh), and Stripe
     * retains idempotency keys for ~24h — so keying on the id alone makes a
     * recycled id collide with an earlier, differently-parameterised request
     * ("Keys for idempotent requests can only be used with the same parameters").
     * Mixing in created_at keeps genuine same-order retries idempotent while
     * keeping recycled ids distinct.
     */
    private function idempotencyKey(string $action, Order $order): string
    {
        return $action.'_order_'.$order->id.'_'.optional($order->created_at)->getTimestamp();
    }

    public function retrievePaymentIntentForOrder(Order $order): ?array
    {
        $ref = $order->stripe_payment_intent_id;
        if (! $ref) {
            return null;
        }

        $client = $this->client();
        $piId = $ref;

        // Until checkout.session.completed lands we only hold the session id;
        // resolve it to the real PaymentIntent first.
        if (str_starts_with($ref, 'cs_')) {
            $session = $client->checkout->sessions->retrieve($ref);
            $piId = $session->payment_intent;
            if (! $piId) {
                return null; // buyer never reached the payment step
            }
        }

        return $client->paymentIntents->retrieve($piId)->toArray();
    }

    public function constructEvent(string $payload, ?string $signature): array
    {
        $event = Webhook::constructEvent($payload, (string) $signature, (string) $this->webhookSecret);

        return $event->toArray();
    }

    private function metadata(Order $order): array
    {
        return [
            'order_id' => (string) $order->id,
            'sale_batch_id' => (string) $order->sale_batch_id,
            'user_id' => (string) $order->user_id,
        ];
    }
}
