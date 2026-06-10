<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Reservation;
use App\Models\SaleBatch;

/** GET /admin/batches/{batch}/stats — thống kê một đợt: đã bán/đang giữ/còn lại/doanh thu. */
class StatsController extends Controller
{
    public function show(SaleBatch $batch)
    {
        $batch->load('course');

        // remaining = capacity - slots_taken; revenue = tổng amount các đơn paid.
        $stats = [
            'capacity' => $batch->capacity,
            'taken' => $batch->slots_taken,
            'remaining' => $batch->remainingSlots(),
            'active_reservations' => Reservation::where('sale_batch_id', $batch->id)
                ->where('status', Reservation::STATUS_ACTIVE)->count(),
            'paid' => Order::where('sale_batch_id', $batch->id)->where('status', Order::STATUS_PAID)->count(),
            'revenue' => (int) Order::where('sale_batch_id', $batch->id)
                ->where('status', Order::STATUS_PAID)->sum('amount'),
        ];

        $orders = Order::with('user')
            ->where('sale_batch_id', $batch->id)
            ->latest()
            ->get();

        return view('admin.batches.stats', compact('batch', 'stats', 'orders'));
    }
}
