<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessStripeEvent;
use App\Payments\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /webhooks/stripe — the only machine-to-machine endpoint. No auth/CSRF;
 * protected by signature verification (spec §8.1, §11). Webhook is the source
 * of truth for payment state (D5).
 *
 * Verify the signature, then hand off to a queued job and return 200 fast so
 * Stripe never times out and retries (jobs_and_scheduler §4, §7).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentGateway $gateway)
    {
        try {
            $event = $gateway->constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature'),
            );
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response('Invalid signature', 400);
        }

        ProcessStripeEvent::dispatch($event);

        return response('ok', 200);
    }
}
