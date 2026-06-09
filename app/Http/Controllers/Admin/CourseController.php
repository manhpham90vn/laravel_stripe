<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function store(Request $request)
    {
        $course = Course::create($this->validated($request));

        return redirect()->route('admin.courses.index')
            ->with('status', "Đã tạo khóa học “{$course->title}”.");
    }

    public function edit(Course $course)
    {
        return view('admin.courses.form', ['course' => $course]);
    }

    public function update(Request $request, Course $course)
    {
        $course->update($this->validated($request, $course->id));

        return redirect()->route('admin.courses.index')
            ->with('status', "Đã cập nhật “{$course->title}”.");
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:courses,slug'.($ignoreId ? ",{$ignoreId}" : '')],
            'summary' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string'],
            'level' => ['nullable', 'string', 'max:100'],
            'lessons_count' => ['nullable', 'integer', 'min:0'],
            'duration_label' => ['nullable', 'string', 'max:100'],
            'outcomes' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published,archived'],
        ]);

        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['title']);
        $data['lessons_count'] = $data['lessons_count'] ?? 0;

        // Outcomes textarea → array (one per line).
        $data['outcomes'] = collect(explode("\n", (string) ($data['outcomes'] ?? '')))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->values()
            ->all();

        return $data;
    }
}
