<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Models\Course;

/** Admin quản lý COURSE: liệt kê / tạo / sửa (CRUD thường, không đụng tiền). */
class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::withCount('batches')->latest()->get();

        return view('admin.courses.index', ['courses' => $courses]);
    }

    public function create()
    {
        return view('admin.courses.form', ['course' => new Course(['status' => 'draft'])]);
    }

    public function store(StoreCourseRequest $request)
    {
        $course = Course::create($request->validated());

        return redirect()->route('admin.courses.index')
            ->with('status', "Đã tạo khóa học \"{$course->title}\".");
    }

    public function edit(Course $course)
    {
        return view('admin.courses.form', ['course' => $course]);
    }

    public function update(UpdateCourseRequest $request, Course $course)
    {
        $course->update($request->validated());

        return redirect()->route('admin.courses.index')
            ->with('status', "Đã cập nhật \"{$course->title}\".");
    }
}
