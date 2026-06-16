<?php

namespace App\Http\Controllers;

use App\Models\SaleBatch;

/** GET /batches/{batch} — trang một đợt bán (status, số chỗ còn, giá, cửa sổ bán). */
class BatchController extends Controller
{
    public function show(SaleBatch $batch)
    {
        $batch->load('course');

        return view('batches.show', ['batch' => $batch]);
    }
}
