<?php

namespace App\Payments;

use App\Models\Order;

interface PaymentGateway
{
    /**
     * Start a hosted checkout for the order. Amount is taken from the order
     * (server-side snapshot, BR-3). Returns where to send the browser.
     */
    public function createCheckout(Order $order): CheckoutSession;

    /** Refund a paid order. The resulting state change arrives via webhook. */
    public function refund(Order $order): void;

    /**
     * Expire the order's Checkout Session so it can no longer be paid, called
     * when the slot-hold TTL elapses or the buyer cancels (§8.2a). A session
     * that is already closed (paid/expired) is a safe no-op.
     */
    public function expireCheckout(Order $order): void;

    /**
     * Fetch the current PaymentIntent for an order as a normalized array
     * (keys: id, status, amount, currency, payment_method_type, latest_charge),
     * or null if none exists yet. Used by the reconciliation safety net
     * (jobs_and_scheduler §5) to converge DB ↔ Stripe when a webhook is missed.
     */
    public function retrievePaymentIntentForOrder(Order $order): ?array;

    /**
     * Verify an incoming webhook payload and return it as a normalized array
     * with keys: id, type, data.object (incl. metadata.order_id).
     *
     * @throws \Throwable when the signature is invalid
     */
    public function constructEvent(string $payload, ?string $signature): array;
}
