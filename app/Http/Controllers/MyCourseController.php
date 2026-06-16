<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use Illuminate\Http\Request;

/** GET /my/courses — "Khóa học của tôi": các enrollment đang active của người dùng. */
class MyCourseController extends Controller
{
    public function index(Request $request)
    {
        $enrollments = $request->user()
            ->enrollments()
            ->with('course')
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->latest('granted_at')
            ->get();

        return view('my.courses', ['enrollments' => $enrollments]);
    }
}
