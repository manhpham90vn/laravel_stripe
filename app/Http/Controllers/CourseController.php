<?php

namespace App\Http\Controllers;

use App\Models\Course;

/** Trang công khai cho người mua: danh sách & chi tiết course (chỉ course `published`). */
class CourseController extends Controller
{
    /** GET /courses — danh sách course đã xuất bản, kèm các đợt bán. */
    public function index()
    {
        $courses = Course::with('batches')
            ->where('status', 'published')
            ->get();

        return view('courses.index', ['courses' => $courses]);
    }

    /** GET /courses/{slug} — chi tiết một course. Course chưa publish → 404. */
    public function show(string $slug)
    {
        $course = Course::with('batches')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return view('courses.show', ['course' => $course]);
    }
}
