<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutException;
use App\Exceptions\GatewayException;
use App\Models\Order;
use App\Models\SaleBatch;
use App\Services\CheckoutService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function __construct(private CheckoutService $checkout) {}

    /** POST /batches/{batch}/checkout — giữ chỗ, tạo đơn, redirect sang Stripe (spec §7.1). */
    public function store(Request $request, SaleBatch $batch)
    {
        try {
            return redirect()->away($this->checkout->initiate($batch, $request->user()));
        } catch (CheckoutException $e) {
            // existingOrder != null → đơn live đã tồn tại, đưa người mua đến đó thay vì về trang batch.
            if ($e->existingOrder) {
                return redirect()->route('orders.show', $e->existingOrder)->with('status', $e->getMessage());
            }

            return redirect()->route('batches.show', $batch)->with('error', $e->getMessage());
        } catch (GatewayException $e) {
            // Đơn đã tạo + chỗ đã giữ → redirect đến orders.show để người mua có thể retry.
            return redirect()->route('orders.show', $e->order)->with('error', $e->getMessage());
        }
    }

    /** POST /orders/{order}/pay — tạo lại Checkout Session cho đơn pending/processing (spec §12). */
    public function pay(Request $request, Order $order)
    {
        $this->authorize('view', $order);
        abort_unless($order->isRetriable(), 409);

        try {
            return redirect()->away($this->checkout->retry($order));
        } catch (GatewayException $e) {
            return redirect()->route('orders.show', $order)->with('error', $e->getMessage());
        }
    }
}
