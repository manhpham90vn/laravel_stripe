<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use Illuminate\Database\QueryException;
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
            return; // already handled — cheap pre-check before doing any work
        }

        $object = $event['data']['object'] ?? [];
        $order = $this->resolveOrder($object);

        // The processed-event marker is the authoritative dedup: each handler
        // writes it FIRST, in the same transaction as its side-effect (§2.8), so
        // a concurrent duplicate trips the unique violation and is dropped there.
        // `ctx` carries the event id/type into that transaction.
        $ctx = ['event_id' => $eventId, 'event_type' => $type];

        if (! $order) {
            Log::warning('Stripe event with no matching order', ['type' => $type, 'event' => $eventId]);
            $this->markProcessed($eventId, $type);

            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handler->onCheckoutCompleted($order, $ctx + [
                'payment_intent_id' => $object['payment_intent'] ?? null,
                'payment_status' => $object['payment_status'] ?? null,
                'payment_method_type' => $object['payment_method_types'][0] ?? null,
            ]),
            'payment_intent.succeeded' => $this->handler->markPaid($order, $ctx + [
                'payment_method_type' => $object['payment_method_type'] ?? null,
                'charge_id' => $object['latest_charge'] ?? ($object['charge'] ?? null),
                'payment_intent_id' => $object['id'] ?? null,
                'amount' => $object['amount_received'] ?? ($object['amount'] ?? null),
            ]),
            'payment_intent.processing' => $this->handler->markProcessing($order, $ctx + [
                'payment_method_type' => $object['payment_method_type'] ?? 'konbini',
            ]),
            'payment_intent.payment_failed',
            'payment_intent.canceled' => $this->handler->markFailed($order, $ctx + [
                'reason' => $object['last_payment_error']['message'] ?? null,
            ]),
            'charge.refunded' => $this->handler->markRefunded($order, $ctx + [
                'charge_id' => $object['id'] ?? null,
            ]),
            'charge.dispute.created' => $this->handler->openDispute($order, $ctx + [
                'dispute_id' => $object['id'] ?? null,
                'dispute_status' => $object['status'] ?? null,
            ]),
            'charge.dispute.closed' => $this->handler->closeDispute($order, $ctx + [
                'dispute_id' => $object['id'] ?? null,
                'dispute_status' => $object['status'] ?? null,
            ]),
            // Nothing to apply, but still record it so retries skip the work.
            default => $this->onUnhandled($eventId, $type),
        };
    }

    private function onUnhandled(string $eventId, string $type): void
    {
        Log::info('Unhandled Stripe event', ['type' => $type]);
        $this->markProcessed($eventId, $type);
    }

    /**
     * Record the marker for events with no order-level side-effect (no match /
     * no order). For events that DO mutate an order the handler writes the marker
     * inside the side-effect transaction instead, so the two stay atomic.
     */
    private function markProcessed(string $eventId, string $type): void
    {
        try {
            ProcessedStripeEvent::create([
                'event_id' => $eventId,
                'type' => $type,
                'processed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // A concurrent delivery already recorded it — fine, it's a no-op event.
        }
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
