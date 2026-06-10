<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Payments\PaymentGateway;

/** POST /admin/orders/{order}/refund — admin hoàn tiền một đơn đã thanh toán (spec §7.4). */
class RefundController extends Controller
{
    public function store(Order $order, PaymentGateway $gateway)
    {
        if ($order->status !== Order::STATUS_PAID) {
            return back()->with('error', 'Chỉ hoàn tiền được đơn đã thanh toán.');
        }

        // Kích hoạt refund; thay đổi trạng thái (refunded + thu hồi enrollment)
        // sẽ về qua webhook charge.refunded — KHÔNG đổi trạng thái tại đây (D5).
        $gateway->refund($order);

        return back()->with('status', "Đã yêu cầu hoàn tiền cho đơn #{$order->id}.");
    }
}
