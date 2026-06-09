<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Models\User;
use App\Payments\PaymentGateway;
use App\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    public function __construct(
        private ReservationService $reservations,
        private PaymentGateway $gateway,
    ) {}

    /**
     * POST /batches/{id}/checkout — reserve a slot, create the order, and
     * redirect to Stripe Checkout (spec §7.1). Business-rule failures redirect
     * back with a flash message (PRG, spec §9).
     */
    public function store(Request $request, int $id)
    {
        $batch = SaleBatch::findOrFail($id);

        try {
            $order = $this->reservations->reserve($batch, $request->user());
        } catch (CheckoutException $e) {
            // Buyer already holds a live order for this batch (e.g. they started
            // checkout, hit Back, and clicked buy again). Send them to that
            // order so they can resume payment or cancel it — not a dead end.
            if ($e->errorCode === 'ALREADY_PURCHASED'
                && $existing = $this->liveOrderFor($batch, $request->user())) {
                $message = $existing->status === Order::STATUS_PAID
                    ? 'Bạn đã mua đợt này rồi.'
                    : 'Bạn đang có đơn chưa hoàn tất cho đợt này. Tiếp tục thanh toán hoặc hủy đơn bên dưới.';

                return redirect()->route('orders.show', $existing)->with('status', $message);
            }

            return redirect()
                ->route('batches.show', $batch)
                ->with('error', $e->getMessage());
        }

        return $this->startCheckout($order, $batch);
    }

    /**
     * POST /orders/{id}/pay — retry Stripe Checkout for an existing pending
     * order (e.g. after a transient gateway error, spec §12). The slot is
     * already held, so no new reservation is made.
     */
    public function pay(Request $request, int $id)
    {
        $order = Order::with('saleBatch')->findOrFail($id);
        $this->authorize('view', $order);

        abort_unless(in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PROCESSING], true), 409);

        return $this->startCheckout($order, $order->saleBatch);
    }

    /** The buyer's still-live order for this batch (BR-2), if any. */
    private function liveOrderFor(SaleBatch $batch, User $user): ?Order
    {
        return Order::where('sale_batch_id', $batch->id)
            ->where('user_id', $user->id)
            ->whereIn('status', Order::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    /** Create the hosted session and redirect; keep the order pending on error. */
    private function startCheckout(Order $order, SaleBatch $batch)
    {
        try {
            $session = $this->gateway->createCheckout($order);
        } catch (\Throwable $e) {
            Log::error('Stripe checkout creation failed', ['order' => $order->id, 'error' => $e->getMessage()]);

            // Slot stays held (released by TTL if abandoned); buyer can retry.
            return redirect()
                ->route('orders.show', $order)
                ->with('error', 'Không khởi tạo được thanh toán. Vui lòng thử lại.');
        }

        return redirect()->away($session->redirectUrl);
    }
}
