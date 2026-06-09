<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentGateway;

class RefundController extends Controller
{
    public function store(Order $order, PaymentGateway $gateway)
    {
        if ($order->status !== Order::STATUS_PAID) {
            return back()->with('error', 'Chỉ hoàn tiền được đơn đã thanh toán.');
        }

        // Triggers the refund; the resulting state change (refunded + enrollment
        // revoked) arrives via the charge.refunded webhook (spec §7.4).
        $gateway->refund($order);

        return back()->with('status', "Đã yêu cầu hoàn tiền cho đơn #{$order->id}.");
    }
}
