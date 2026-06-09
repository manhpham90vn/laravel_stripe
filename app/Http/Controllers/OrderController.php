<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentEventHandler;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function show(Request $request, int $id)
    {
        $order = Order::with('saleBatch.course')->findOrFail($id);
        $this->authorize('view', $order);

        return view('orders.show', ['order' => $order]);
    }

    public function cancel(Request $request, int $id, PaymentEventHandler $handler)
    {
        $order = Order::findOrFail($id);
        $this->authorize('cancel', $order);

        $handler->cancel($order, $request->user()->id);

        return redirect()
            ->route('orders.show', $order)
            ->with('status', 'Đơn đã được hủy và slot đã được nhả.');
    }
}
