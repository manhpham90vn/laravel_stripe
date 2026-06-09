<?php

namespace App\Http\Controllers;

use App\Models\SaleBatch;

class BatchController extends Controller
{
    public function show(int $id)
    {
        $batch = SaleBatch::with('course')->findOrFail($id);

        return view('batches.show', ['batch' => $batch]);
    }
}
