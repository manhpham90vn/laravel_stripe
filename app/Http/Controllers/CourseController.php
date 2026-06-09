<?php

namespace App\Http\Controllers;

use App\Models\Course;

class CourseController extends Controller
{
    public function index()
    {
        $courses = Course::with('batches')
            ->where('status', 'published')
            ->get();

        return view('courses.index', ['courses' => $courses]);
    }

    public function show(string $slug)
    {
        $course = Course::with('batches')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return view('courses.show', ['course' => $course]);
    }
}
