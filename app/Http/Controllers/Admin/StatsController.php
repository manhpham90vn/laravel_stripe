<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaleBatch;

/** GET /admin/batches/{batch}/stats — thống kê một đợt: đã bán/đang giữ/còn lại/doanh thu. */
class StatsController extends Controller
{
    public function show(SaleBatch $batch)
    {
        $batch->load('course');

        $stats = [
            'capacity'            => $batch->capacity,
            'taken'               => $batch->slots_taken,
            'remaining'           => $batch->remainingSlots(),
            'active_reservations' => $batch->activeReservationsCount(),
            'paid'                => $batch->paidOrdersCount(),
            'revenue'             => $batch->revenue(),
        ];

        $orders = $batch->orders()->with('user')->latest()->get();

        return view('admin.batches.stats', compact('batch', 'stats', 'orders'));
    }
}
