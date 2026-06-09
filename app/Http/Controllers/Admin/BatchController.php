<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\SaleBatch;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BatchController extends Controller
{
    public function index(Course $course)
    {
        $course->load(['batches' => fn ($q) => $q->latest()]);

        return view('admin.batches.index', ['course' => $course]);
    }

    public function store(Request $request, Course $course)
    {
        $data = $this->validated($request);

        $course->batches()->create([
            ...$data,
            'slots_taken' => 0,
            'currency' => 'JPY',
        ]);

        return redirect()->route('admin.courses.batches.index', $course)
            ->with('status', 'Đã tạo đợt mở bán.');
    }

    public function update(Request $request, SaleBatch $batch, AuditLogger $audit)
    {
        $data = $this->validated($request);

        $from = $batch->status;
        $batch->update($data);

        if ($from !== $batch->status) {
            $audit->record($batch, $from, $batch->status, 'admin', $request->user()->id);
        }

        return redirect()->route('admin.courses.batches.index', $batch->course_id)
            ->with('status', 'Đã cập nhật đợt.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'price' => ['required', 'integer', 'min:0'],
            'sale_starts_at' => ['required', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after_or_equal:sale_starts_at'],
            'status' => ['required', Rule::in(['scheduled', 'on_sale', 'sold_out', 'closed'])],
        ]);
    }
}
