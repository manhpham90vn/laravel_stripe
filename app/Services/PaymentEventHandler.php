<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProcessedStripeEvent;
use App\Models\Reservation;
use App\Payments\PaymentGateway;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies payment outcomes to an order. Every method is idempotent and
 * transactional — safe to call again on a webhook retry (BR-5/NFR-2).
 * These transitions are the ONLY way an order leaves `pending` (spec §5.1).
 */
class PaymentEventHandler
{
    /**
     * The single source of truth for legal order status transitions (spec §5.1).
     * Anything not listed here is rejected by transition(), so impossible jumps
     * like `canceled → paid` or refunding a `pending` order are blocked at the
     * root instead of relying on per-method guards.
     */
    private const ALLOWED = [
        Order::STATUS_PENDING => [Order::STATUS_PROCESSING, Order::STATUS_PAID, Order::STATUS_FAILED, Order::STATUS_CANCELED],
        Order::STATUS_PROCESSING => [Order::STATUS_PAID, Order::STATUS_FAILED, Order::STATUS_CANCELED],
        Order::STATUS_PAID => [Order::STATUS_REFUNDED, Order::STATUS_DISPUTED],
        Order::STATUS_DISPUTED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        // failed/canceled are terminal for the normal flow; the only way out is
        // the reclaim-or-refund recovery in markPaid() when a real `succeeded`
        // lands late (spec §8.2a) — reclaim → paid, or auto-refund → refunded.
        Order::STATUS_FAILED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        Order::STATUS_CANCELED => [Order::STATUS_PAID, Order::STATUS_REFUNDED],
        Order::STATUS_REFUNDED => [],   // terminal
    ];

    public function __construct(
        private ReservationService $reservations,
        private EnrollmentService $enrollments,
        private AuditLogger $audit,
        private PaymentGateway $gateway,
    ) {}

    /**
     * Run $work inside one transaction that ALSO writes the processed-event
     * marker first (payment_solutions §2.8): dedup and side-effect commit or
     * roll back as a unit, so we never "marked but didn't act", and a lost job
     * that rolls back is safely retried by Stripe. A genuine duplicate trips the
     * primary-key unique violation and is swallowed (already handled); a
     * deadlock / lost connection is rethrown so the queue retries it.
     *
     * $eventId is null when the caller is not a webhook (reconcile / TTL job):
     * then there is no marker to write — $work just runs in its own transaction,
     * relying on the per-order idempotency already baked into each handler.
     */
    private function applyEvent(?string $eventId, ?string $eventType, Closure $work): void
    {
        try {
            DB::transaction(function () use ($eventId, $eventType, $work) {
                if ($eventId !== null) {
                    ProcessedStripeEvent::create([
                        'event_id' => $eventId,
                        'type' => $eventType ?? 'unknown',
                        'processed_at' => now(),
                    ]);
                }

                $work();
            });
        } catch (QueryException $e) {
            if ($eventId !== null && $this->isUniqueViolation($e)) {
                return; // duplicate delivery — the first one already applied it
            }

            throw $e;
        }
    }

    /** Detect the marker's primary-key clash across sqlite / mysql / postgres. */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        return $sqlState === '23505'                 // Postgres unique_violation
            || ($sqlState === '23000' && $driverCode === 1062) // MySQL ER_DUP_ENTRY
            || ($driverCode === 19 || $driverCode === 2067)    // SQLite CONSTRAINT / CONSTRAINT_UNIQUE
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Move an order to $to if the jump is legal, persisting $attributes and the
     * audit trail in one go. Returns whether the transition happened, so callers
     * gate their side effects on it. Illegal jumps are logged and swallowed (not
     * thrown) so the webhook still ACKs (BR-5); same-status replays are the
     * expected idempotent no-op and stay silent.
     */
    private function transition(
        Order $order,
        string $to,
        array $attributes = [],
        string $actor = 'system',
        ?int $actorId = null,
        array $meta = [],
    ): bool {
        $from = $order->status;

        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            if ($to !== $from) {
                Log::warning('Blocked illegal order transition', [
                    'order_id' => $order->id, 'from' => $from, 'to' => $to,
                ]);
            }

            return false;
        }

        $order->update(['status' => $to] + $attributes);
        $this->audit->record($order, $from, $to, $actor, $actorId, $meta);

        return true;
    }

    /**
     * payment_intent.succeeded → paid + enrollment (spec §8.2).
     *
     * Normal path (pending/processing): the slot is still held, so just consume
     * it and grant access. Recovery path (canceled/failed): the slot was already
     * released, so reclaim-or-refund (§8.2a) — try to grab a seat back; if none
     * is free, refund the late charge. The Stripe refund call is deferred until
     * after the transaction commits so we never hold row locks across a network
     * call.
     */
    public function markPaid(Order $order, array $meta = []): void
    {
        $refundOrderId = null;

        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta, &$refundOrderId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            if ($order->status === Order::STATUS_PAID) {
                return; // idempotent — already settled
            }

            // BR-11 / §2.9: never grant on an amount that doesn't match the
            // server-side snapshot. Hold for ops rather than silently enrolling.
            if (isset($meta['amount']) && (int) $meta['amount'] !== (int) $order->amount) {
                Log::warning('Amount mismatch on succeeded payment — not granting', [
                    'order_id' => $order->id, 'db' => $order->amount, 'stripe' => $meta['amount'],
                ]);

                return;
            }

            $paidAttributes = [
                'paid_at' => now(),
                'payment_method_type' => $meta['payment_method_type'] ?? $order->payment_method_type,
                'stripe_charge_id' => $meta['charge_id'] ?? $order->stripe_charge_id,
                // Replace the Checkout session placeholder with the real PI id so
                // refunds / disputes / reconciliation resolve by PaymentIntent.
                'stripe_payment_intent_id' => $meta['payment_intent_id'] ?? $order->stripe_payment_intent_id,
            ];

            // Normal path — the hold is still in place.
            if (in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true)) {
                if (! $this->transition($order, Order::STATUS_PAID, $paidAttributes, 'webhook', null, $meta)) {
                    return;
                }

                if ($order->reservation) {
                    $this->reservations->consume($order->reservation);
                }

                $order->loadMissing('saleBatch');
                $this->enrollments->grant($order);

                return;
            }

            // Recovery path (§8.2a) — slot already released; reclaim or refund.
            if (in_array($order->status, [Order::STATUS_CANCELED, Order::STATUS_FAILED], true)) {
                if ($this->reservations->reclaim($order)) {
                    if ($this->transition($order, Order::STATUS_PAID, $paidAttributes, 'webhook', null, $meta + ['recovery' => 'reclaimed'])) {
                        $order->loadMissing('saleBatch');
                        $this->enrollments->grant($order);
                    }

                    return;
                }

                // No seat available — persist the charge ref and refund it after
                // commit; the charge.refunded webhook converges order → refunded.
                $order->update([
                    'stripe_charge_id' => $meta['charge_id'] ?? $order->stripe_charge_id,
                    'stripe_payment_intent_id' => $meta['payment_intent_id'] ?? $order->stripe_payment_intent_id,
                ]);

                if ($order->stripe_charge_id) {
                    $refundOrderId = $order->id;
                } else {
                    Log::error('Late payment on dead order but no charge to refund', [
                        'order_id' => $order->id,
                    ]);
                }
            }
        });

        if ($refundOrderId !== null) {
            Log::warning('Late payment on a sold-out dead order — auto-refunding', [
                'order_id' => $refundOrderId,
            ]);
            $this->gateway->refund(Order::findOrFail($refundOrderId));
        }
    }

    /**
     * checkout.session.completed → capture the real PaymentIntent id and, when
     * the session finished without being paid (async voucher placed), move the
     * order to processing so the slot is held to voucher expiry (spec §8.2).
     */
    public function onCheckoutCompleted(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            if ($pi = $meta['payment_intent_id'] ?? null) {
                Order::whereKey($order->id)
                    ->where(fn ($q) => $q->whereNull('stripe_payment_intent_id')->orWhere('stripe_payment_intent_id', '!=', $pi))
                    ->update(['stripe_payment_intent_id' => $pi]);
                $order->refresh();
            }

            // Synchronous success arrives via payment_intent.succeeded; only act
            // here for the async case (voucher placed, payment_status unpaid).
            // The inner call passes no event_id so it nests without a 2nd marker.
            if (($meta['payment_status'] ?? 'paid') !== 'paid' && $order->status === Order::STATUS_PENDING) {
                $this->markProcessing($order, ['payment_method_type' => $meta['payment_method_type'] ?? 'konbini']);
            }
        });
    }

    /** payment_intent.processing → async voucher placed, slot held (spec §7.2). */
    public function markProcessing(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            $ok = $this->transition($order, Order::STATUS_PROCESSING, [
                'payment_method_type' => $meta['payment_method_type'] ?? 'konbini',
            ], 'webhook', null, $meta);

            if (! $ok) {
                return;
            }

            $this->reservations->extendForAsync($order);
        });
    }

    /** payment_intent.payment_failed → fail + release the slot. */
    public function markFailed(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            // paid → failed is rejected by the table (a late failure after
            // success), as are failed/canceled replays.
            if (! $this->transition($order, Order::STATUS_FAILED, [], 'webhook', null, $meta)) {
                return;
            }

            if ($order->reservation) {
                $this->reservations->release($order->reservation, Reservation::STATUS_RELEASED);
            }
        });
    }

    /** Buyer-initiated cancel of a pending order (spec §9). */
    public function cancel(Order $order, int $actorId): void
    {
        $this->cancelOrder($order, 'user', $actorId);
    }

    /** TTL elapsed — system cancels the order and frees the slot (spec §7.3, job). */
    public function expire(Order $order): void
    {
        $this->cancelOrder($order, 'system', null);
    }

    private function cancelOrder(Order $order, string $actor, ?int $actorId): void
    {
        $canceled = DB::transaction(function () use ($order, $actor, $actorId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            // Only pending/processing orders may be canceled (spec §5.1).
            if (! $this->transition($order, Order::STATUS_CANCELED, [], $actor, $actorId)) {
                return false;
            }

            if ($order->reservation) {
                $this->reservations->release($order->reservation, Reservation::STATUS_RELEASED);
            }

            return true;
        });

        // After the slot is freed, close the Checkout Session so the buyer can't
        // pay a seat they no longer hold (§8.2a). Deferred past the commit so we
        // never hold the row lock across the Stripe call; if a payment slipped in
        // first, reclaim-or-refund in markPaid() is the backstop.
        if ($canceled) {
            $this->gateway->expireCheckout($order);
        }
    }

    /** charge.refunded → refunded + revoke enrollment (BR-7; slot NOT auto-freed). */
    public function markRefunded(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $this->transition($order, Order::STATUS_REFUNDED, [], 'webhook', null, $meta)) {
                return;
            }

            $this->enrollments->revoke($order);
        });
    }

    /** charge.dispute.created → funds held, outcome pending (spec §5.1, §8.2). */
    public function openDispute(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->transition($order, Order::STATUS_DISPUTED, [], 'webhook', null, $meta);
        });
    }

    /**
     * charge.dispute.closed → resolve by outcome (spec §5.1):
     *   won / warning_closed → merchant kept funds → back to paid (access stays)
     *   lost (or other)      → customer won → treat as refunded, revoke access
     */
    public function closeDispute(Order $order, array $meta = []): void
    {
        $this->applyEvent($meta['event_id'] ?? null, $meta['event_type'] ?? null, function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $outcome = $meta['dispute_status'] ?? null;

            if (in_array($outcome, ['won', 'warning_closed'], true)) {
                // Only a disputed order can swing back to paid; the table drops
                // anything else (e.g. an already-settled refund).
                $this->transition($order, Order::STATUS_PAID, [], 'webhook', null, $meta);

                return;
            }

            if (! $this->transition($order, Order::STATUS_REFUNDED, [], 'webhook', null, $meta)) {
                return;
            }

            $this->enrollments->revoke($order);
        });
    }
}
