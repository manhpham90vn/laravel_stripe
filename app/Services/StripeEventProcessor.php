<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for Stripe payment events, shared by the webhook job and
 * the reconciliation safety net so they apply identical logic.
 *
 * Idempotency (BR-5): each event.id is recorded in processed_stripe_events and
 * skipped on replay. The webhook is the source of truth (D5).
 */
class StripeEventProcessor
{
    public function __construct(private PaymentEventHandler $handler) {}

    /** @param array{id:string,type:string,data:array} $event */
    public function process(array $event): void
    {
        $eventId = $event['id'] ?? null;
        $type = $event['type'] ?? '';

        if (! $eventId) {
            Log::warning('Stripe event without id', ['type' => $type]);

            return;
        }

        if (ProcessedStripeEvent::find($eventId)) {
            return; // already handled
        }

        $object = $event['data']['object'] ?? [];
        $order = $this->resolveOrder($object);

        if ($order) {
            match ($type) {
                'checkout.session.completed' => $this->handler->onCheckoutCompleted($order, [
                    'payment_intent_id' => $object['payment_intent'] ?? null,
                    'payment_status' => $object['payment_status'] ?? null,
                    'payment_method_type' => $object['payment_method_types'][0] ?? null,
                ]),
                'payment_intent.succeeded' => $this->handler->markPaid($order, [
                    'payment_method_type' => $object['payment_method_type'] ?? null,
                    'charge_id' => $object['latest_charge'] ?? ($object['charge'] ?? null),
                    'payment_intent_id' => $object['id'] ?? null,
                    'amount' => $object['amount_received'] ?? ($object['amount'] ?? null),
                ]),
                'payment_intent.processing' => $this->handler->markProcessing($order, [
                    'payment_method_type' => $object['payment_method_type'] ?? 'konbini',
                ]),
                'payment_intent.payment_failed',
                'payment_intent.canceled' => $this->handler->markFailed($order, [
                    'reason' => $object['last_payment_error']['message'] ?? null,
                ]),
                'charge.refunded' => $this->handler->markRefunded($order, [
                    'charge_id' => $object['id'] ?? null,
                ]),
                'charge.dispute.created' => $this->handler->openDispute($order, [
                    'dispute_id' => $object['id'] ?? null,
                    'dispute_status' => $object['status'] ?? null,
                ]),
                'charge.dispute.closed' => $this->handler->closeDispute($order, [
                    'dispute_id' => $object['id'] ?? null,
                    'dispute_status' => $object['status'] ?? null,
                ]),
                default => Log::info('Unhandled Stripe event', ['type' => $type]),
            };
        } else {
            Log::warning('Stripe event with no matching order', ['type' => $type, 'event' => $eventId]);
        }

        ProcessedStripeEvent::create([
            'event_id' => $eventId,
            'type' => $type,
            'processed_at' => now(),
        ]);
    }

    /** Map an event object back to our order via metadata, PI, or charge (spec §8.1). */
    private function resolveOrder(array $object): ?Order
    {
        if ($orderId = $object['metadata']['order_id'] ?? null) {
            return Order::find($orderId);
        }

        // Disputes/charges carry the PI; payment_intent.* events are the PI itself.
        if ($pi = $object['payment_intent'] ?? null) {
            if ($order = Order::where('stripe_payment_intent_id', $pi)->first()) {
                return $order;
            }
        }

        if ($charge = $object['charge'] ?? null) {
            if ($order = Order::where('stripe_charge_id', $charge)->first()) {
                return $order;
            }
        }

        if ($id = $object['id'] ?? null) {
            return Order::where('stripe_payment_intent_id', $id)->first();
        }

        return null;
    }
}
