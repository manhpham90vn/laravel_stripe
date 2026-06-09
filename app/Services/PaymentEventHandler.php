<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Reservation;
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
        Order::STATUS_FAILED => [],     // terminal
        Order::STATUS_CANCELED => [],   // terminal
        Order::STATUS_REFUNDED => [],   // terminal
    ];

    public function __construct(
        private ReservationService $reservations,
        private EnrollmentService $enrollments,
        private AuditLogger $audit,
    ) {}

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

    /** payment_intent.succeeded → paid + enrollment (spec §8.2). */
    public function markPaid(Order $order, array $meta = []): void
    {
        DB::transaction(function () use ($order, $meta) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            $ok = $this->transition($order, Order::STATUS_PAID, [
                'paid_at' => now(),
                'payment_method_type' => $meta['payment_method_type'] ?? $order->payment_method_type,
                'stripe_charge_id' => $meta['charge_id'] ?? $order->stripe_charge_id,
                // Replace the Checkout session placeholder with the real PI id so
                // refunds / disputes / reconciliation resolve by PaymentIntent.
                'stripe_payment_intent_id' => $meta['payment_intent_id'] ?? $order->stripe_payment_intent_id,
            ], 'webhook', null, $meta);

            if (! $ok) {
                return;
            }

            if ($order->reservation) {
                $this->reservations->consume($order->reservation);
            }

            $order->loadMissing('saleBatch');
            $this->enrollments->grant($order);
        });
    }

    /**
     * checkout.session.completed → capture the real PaymentIntent id and, when
     * the session finished without being paid (async voucher placed), move the
     * order to processing so the slot is held to voucher expiry (spec §8.2).
     */
    public function onCheckoutCompleted(Order $order, array $meta = []): void
    {
        if ($pi = $meta['payment_intent_id'] ?? null) {
            Order::whereKey($order->id)
                ->where(fn ($q) => $q->whereNull('stripe_payment_intent_id')->orWhere('stripe_payment_intent_id', '!=', $pi))
                ->update(['stripe_payment_intent_id' => $pi]);
            $order->refresh();
        }

        // Synchronous success arrives via payment_intent.succeeded; only act here
        // for the async case (voucher placed, payment_status still unpaid).
        if (($meta['payment_status'] ?? 'paid') !== 'paid' && $order->status === Order::STATUS_PENDING) {
            $this->markProcessing($order, ['payment_method_type' => $meta['payment_method_type'] ?? 'konbini']);
        }
    }

    /** payment_intent.processing → async voucher placed, slot held (spec §7.2). */
    public function markProcessing(Order $order, array $meta = []): void
    {
        DB::transaction(function () use ($order, $meta) {
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
        DB::transaction(function () use ($order, $meta) {
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
        DB::transaction(function () use ($order, $actor, $actorId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->with('reservation')->firstOrFail();

            // Only pending/processing orders may be canceled (spec §5.1).
            if (! $this->transition($order, Order::STATUS_CANCELED, [], $actor, $actorId)) {
                return;
            }

            if ($order->reservation) {
                $this->reservations->release($order->reservation, Reservation::STATUS_RELEASED);
            }
        });
    }

    /** charge.refunded → refunded + revoke enrollment (BR-7; slot NOT auto-freed). */
    public function markRefunded(Order $order, array $meta = []): void
    {
        DB::transaction(function () use ($order, $meta) {
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
        DB::transaction(function () use ($order, $meta) {
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
        DB::transaction(function () use ($order, $meta) {
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
