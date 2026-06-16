<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * Gateway-level guarantees that need the real Stripe SDK request building but no
 * network: we swap in a recording HTTP client and inspect the requests the SDK
 * would have sent (method, path, Idempotency-Key header).
 *
 *  • Issue 2.16 — at most one live session per order: creating a session expires
 *    the previous one first.
 *  • Issue 2.17 — idempotency keys derive from stable business data (order id +
 *    created_at), so a retry reuses the key and refund gets a distinct one.
 */
class StripeGatewaySessionTest extends TestCase
{
    use PaymentFixtures;
    use RefreshDatabase;

    private RecordingHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new RecordingHttpClient();
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null); // reset the global SDK client
        parent::tearDown();
    }

    private function gateway(): StripeGateway
    {
        return new StripeGateway('sk_test_x', 'whsec_x');
    }

    /** 2.16: a retry expires the prior open session before opening a new one. */
    public function test_create_checkout_expires_the_previous_session_first(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_checkout_session_id' => 'cs_old']);

        $this->http->enqueueSession('cs_new', 'pi_new');
        $this->gateway()->createCheckout($order->fresh());

        $paths = array_column($this->http->requests, 'path');

        // The expire call must come before the create call.
        $expireIndex = $this->firstIndexMatching($paths, '#/checkout/sessions/cs_old/expire$#');
        $createIndex = $this->firstIndexMatching($paths, '#/checkout/sessions$#');

        $this->assertNotNull($expireIndex, 'expected an expire call for the old session');
        $this->assertNotNull($createIndex, 'expected a create call for the new session');
        $this->assertLessThan($createIndex, $expireIndex, 'old session must be expired before the new one is created');
    }

    /** A brand-new order (no prior session) only creates — nothing to expire. */
    public function test_create_checkout_does_not_expire_when_there_is_no_prior_session(): void
    {
        $order = $this->reserve($this->onSaleBatch()); // stripe_checkout_session_id is null

        $this->http->enqueueSession('cs_new', 'pi_new');
        $this->gateway()->createCheckout($order->fresh());

        $paths = array_column($this->http->requests, 'path');
        $this->assertNull(
            $this->firstIndexMatching($paths, '#/expire$#'),
            'no expire call should be made when the order has no live session',
        );
    }

    /** 2.17: the checkout idempotency key is stable across retries of one order. */
    public function test_checkout_idempotency_key_is_stable_per_order(): void
    {
        $order = $this->reserve($this->onSaleBatch());

        $this->http->enqueueSession('cs_a', 'pi_a');
        $this->gateway()->createCheckout($order->fresh());
        $firstKey = $this->lastIdempotencyKeyFor('#/checkout/sessions$#');

        $this->http->enqueueSession('cs_b', 'pi_b');
        $this->gateway()->createCheckout($order->fresh());
        $secondKey = $this->lastIdempotencyKeyFor('#/checkout/sessions$#');

        $this->assertNotNull($firstKey);
        $this->assertStringContainsString('checkout_order_'.$order->id.'_', $firstKey);
        $this->assertSame($firstKey, $secondKey, 'retrying the same order must reuse the key (§2.17)');
    }

    /** 2.17: refund derives a distinct key from the same order — not the checkout one. */
    public function test_refund_uses_a_distinct_idempotency_key(): void
    {
        $order = $this->reserve($this->onSaleBatch());
        $order->update(['stripe_charge_id' => 'ch_1']);

        $this->http->enqueueJson(['id' => 're_1', 'object' => 'refund']);
        $this->gateway()->refund($order->fresh());

        $refundKey = $this->lastIdempotencyKeyFor('#/refunds$#');
        $this->assertNotNull($refundKey);
        $this->assertStringContainsString('refund_order_'.$order->id.'_', $refundKey);
        $this->assertStringNotContainsString('checkout_', $refundKey);
    }

    private function firstIndexMatching(array $paths, string $pattern): ?int
    {
        foreach ($paths as $i => $path) {
            if (preg_match($pattern, $path)) {
                return $i;
            }
        }

        return null;
    }

    private function lastIdempotencyKeyFor(string $pathPattern): ?string
    {
        $key = null;
        foreach ($this->http->requests as $req) {
            if (! preg_match($pathPattern, $req['path'])) {
                continue;
            }
            foreach ($req['headers'] as $header) {
                if (stripos($header, 'Idempotency-Key:') === 0) {
                    $key = trim(substr($header, strlen('Idempotency-Key:')));
                }
            }
        }

        return $key;
    }
}

/**
 * Minimal Stripe HTTP client that records every request and returns canned
 * JSON bodies from a FIFO queue — no network. Mirrors ClientInterface::request.
 */
class RecordingHttpClient implements ClientInterface
{
    /** @var list<array{method:string,path:string,headers:array,params:array}> */
    public array $requests = [];

    /** @var list<string> queued raw JSON response bodies */
    private array $responses = [];

    public function enqueueJson(array $body): void
    {
        $this->responses[] = json_encode($body);
    }

    public function enqueueSession(string $id, string $paymentIntent): void
    {
        $this->enqueueJson([
            'id' => $id,
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/'.$id,
            'payment_intent' => $paymentIntent,
        ]);
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = [
            'method' => $method,
            'path' => parse_url($absUrl, PHP_URL_PATH) ?: $absUrl,
            'headers' => $headers,
            'params' => $params,
        ];

        // Fall back to a complete session-shaped body so any extra call (e.g. the
        // expire that precedes a create) returns something the SDK can read.
        $body = $this->responses ? array_shift($this->responses) : json_encode([
            'id' => 'cs_fallback',
            'object' => 'checkout.session',
            'url' => 'https://checkout.stripe.com/c/pay/cs_fallback',
            'payment_intent' => 'pi_fallback',
        ]);

        return [$body, 200, []];
    }
}
