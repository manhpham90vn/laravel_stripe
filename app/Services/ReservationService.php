<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phương án A — Reserve-with-timeout (spec §6). Every mutation of
 * sale_batches.slots_taken happens inside a transaction holding a row lock on
 * the batch, which is what makes overselling impossible (NFR-1 / BR-1).
 */
class ReservationService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Claim a slot for $user on $batch and create a pending order.
     *
     * @throws CheckoutException SOLD_OUT | ALREADY_PURCHASED | BATCH_NOT_ON_SALE
     */
    public function reserve(SaleBatch $batch, User $user): Order
    {
        return DB::transaction(function () use ($batch, $user) {
            // Row lock — serializes concurrent buyers on the same batch.
            $batch = SaleBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();

            if ($batch->status !== SaleBatch::STATUS_ON_SALE || ! $batch->isWithinWindow()) {
                throw CheckoutException::notOnSale();
            }

            if ($batch->slots_taken >= $batch->capacity) {
                throw CheckoutException::soldOut();
            }

            // BR-2: at most one live order / active reservation per (user, batch).
            $hasLiveOrder = Order::where('sale_batch_id', $batch->id)
                ->where('user_id', $user->id)
                ->whereIn('status', Order::LIVE_STATUSES)
                ->exists();

            $hasActiveReservation = Reservation::where('sale_batch_id', $batch->id)
                ->where('user_id', $user->id)
                ->where('status', Reservation::STATUS_ACTIVE)
                ->exists();

            if ($hasLiveOrder || $hasActiveReservation) {
                throw CheckoutException::alreadyPurchased();
            }

            $ttl = now()->addMinutes((int) config('payment.ttl.card_minutes'));

            $reservation = Reservation::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $user->id,
                'status' => Reservation::STATUS_ACTIVE,
                'reserved_until' => $ttl,
            ]);

            $batch->slots_taken++;
            if ($batch->slots_taken >= $batch->capacity) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_SOLD_OUT;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_SOLD_OUT, 'system');
            }
            $batch->save();

            $order = Order::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $user->id,
                'reservation_id' => $reservation->id,
                'status' => Order::STATUS_PENDING,
                'amount' => $batch->price,                  // BR-3: server-side snapshot
                'currency' => $batch->currency,
                'reserved_until' => $ttl,
            ]);

            $this->audit->record($order, null, Order::STATUS_PENDING, 'user', $user->id);

            return $order;
        });
    }

    /**
     * Release a held slot: flip the reservation and decrement the batch.
     * Idempotent — a reservation that is no longer active is a no-op.
     */
    public function release(Reservation $reservation, string $toStatus = Reservation::STATUS_EXPIRED): void
    {
        DB::transaction(function () use ($reservation, $toStatus) {
            $batch = SaleBatch::whereKey($reservation->sale_batch_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== Reservation::STATUS_ACTIVE) {
                return; // already consumed/released/expired
            }

            $reservation->status = $toStatus;
            $reservation->save();

            if ($batch->slots_taken > 0) {
                $batch->slots_taken--;
            }

            // A freed slot can reopen a sold-out batch if still within window.
            if ($batch->status === SaleBatch::STATUS_SOLD_OUT && $batch->isWithinWindow()) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_ON_SALE;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_ON_SALE, 'system');
            }
            $batch->save();
        });
    }

    /**
     * Re-acquire a slot for an order whose hold was already released — a late
     * `payment_intent.succeeded` landed on a canceled/failed order (spec §8.2a
     * reclaim-or-refund). Returns false when no seat is free, or when the buyer
     * already holds another live order for the batch (BR-2): in both cases the
     * caller must refund the late charge instead of resurrecting this order.
     */
    public function reclaim(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $batch = SaleBatch::whereKey($order->sale_batch_id)->lockForUpdate()->firstOrFail();

            // Another live/paid order for this (user, batch) already holds the
            // seat — resurrecting this one would double-book and break BR-2.
            $hasOtherLive = Order::where('sale_batch_id', $batch->id)
                ->where('user_id', $order->user_id)
                ->where('id', '!=', $order->id)
                ->whereIn('status', Order::LIVE_STATUSES)
                ->exists();

            if ($hasOtherLive || $batch->slots_taken >= $batch->capacity) {
                return false;
            }

            // The freed reservation is gone; record a fresh consumed one so the
            // seat is permanently attributed to this order.
            $reservation = Reservation::create([
                'sale_batch_id' => $batch->id,
                'user_id' => $order->user_id,
                'status' => Reservation::STATUS_CONSUMED,
                'reserved_until' => now(),
            ]);

            $batch->slots_taken++;
            if ($batch->slots_taken >= $batch->capacity && $batch->status === SaleBatch::STATUS_ON_SALE) {
                $prev = $batch->status;
                $batch->status = SaleBatch::STATUS_SOLD_OUT;
                $this->audit->record($batch, $prev, SaleBatch::STATUS_SOLD_OUT, 'system');
            }
            $batch->save();

            $order->update(['reservation_id' => $reservation->id]);

            return true;
        });
    }

    /** Mark a reservation consumed (its slot is now permanently taken). */
    public function consume(Reservation $reservation): void
    {
        if ($reservation->status === Reservation::STATUS_ACTIVE) {
            $reservation->update(['status' => Reservation::STATUS_CONSUMED]);
        }
    }

    /** Async methods hold the slot until the voucher expires (BR-8). */
    public function extendForAsync(Order $order): void
    {
        $until = now()->addDays((int) config('payment.ttl.async_days'));

        if ($order->reservation && $order->reservation->status === Reservation::STATUS_ACTIVE) {
            $order->reservation->update(['reserved_until' => $until]);
        }
        $order->update(['reserved_until' => $until]);
    }
}
