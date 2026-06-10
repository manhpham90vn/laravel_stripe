<?php

namespace App\Http\Controllers;

use App\Models\SaleBatch;

/** GET /batches/{id} — trang một đợt bán (status, số chỗ còn, giá, cửa sổ bán). */
class BatchController extends Controller
{
    public function show(int $id)
    {
        $batch = SaleBatch::with('course')->findOrFail($id);

        return view('batches.show', ['batch' => $batch]);
    }
}
