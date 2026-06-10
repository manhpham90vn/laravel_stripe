<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentEventHandler;
use Illuminate\Http\Request;

/**
 * Các route đơn hàng phía người mua: xem trạng thái đơn và hủy đơn (spec §9).
 */
class OrderController extends Controller
{
    /** GET /orders/{id} — trang trạng thái đơn. authorize('view') chặn xem đơn người khác (§11). */
    public function show(Request $request, int $id)
    {
        $order = Order::with('saleBatch.course')->findOrFail($id);
        $this->authorize('view', $order);

        return view('orders.show', ['order' => $order]);
    }

    /**
     * POST /orders/{id}/cancel — người mua tự hủy đơn đang chờ. Chỉ chủ đơn được
     * hủy (policy 'cancel'). Việc hủy thật (đổi trạng thái, nhả chỗ, đóng session)
     * nằm trong PaymentEventHandler::cancel.
     *
     * KHÔNG cần guard status ở đây: nếu đơn đã `paid`/`refunded`/`canceled` (vd
     * người mua bấm Hủy sau khi webhook vừa lên paid), handler gọi transition()
     * và bảng ALLOWED LẶNG LẼ TỪ CHỐI bước nhảy → no-op an toàn, không nhả chỗ
     * của một đơn đã trả. Đây là lý do controller chỉ cần check quyền sở hữu.
     */
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
