<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BatchRequest;
use App\Models\Course;
use App\Models\SaleBatch;
use App\Services\BatchService;

/** Admin quản lý các ĐỢT BÁN (sale_batches) của một course: liệt kê / tạo / sửa. */
class BatchController extends Controller
{
    public function __construct(private BatchService $batch) {}

    /** GET /admin/courses/{course}/batches — danh sách đợt của course (mới nhất trước). */
    public function index(Course $course)
    {
        $course->load(['batches' => fn ($q) => $q->latest()]);

        return view('admin.batches.index', ['course' => $course]);
    }

    /** POST — tạo đợt mới. */
    public function store(BatchRequest $request, Course $course)
    {
        $this->batch->create($course, $request->validated());

        return redirect()->route('admin.courses.batches.index', $course)
            ->with('status', 'Đã tạo đợt mở bán.');
    }

    /** PATCH — sửa/đóng đợt. */
    public function update(BatchRequest $request, SaleBatch $batch)
    {
        $this->batch->update($batch, $request->validated(), $request->user()->id);

        return redirect()->route('admin.courses.batches.index', $batch->course_id)
            ->with('status', 'Đã cập nhật đợt.');
    }
}
