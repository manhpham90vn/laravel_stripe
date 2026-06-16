<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    use NormalizesCourseData;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        // Route model binding đã resolve {course} thành model — lấy id từ model, không dùng $this->route('id').
        $courseId = $this->route('course')->id;

        return [
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:255', Rule::unique('courses', 'slug')->ignore($courseId)],
            'summary'        => ['required', 'string', 'max:500'],
            'description'    => ['required', 'string'],
            'level'          => ['nullable', 'string', 'max:100'],
            'lessons_count'  => ['nullable', 'integer', 'min:0'],
            'duration_label' => ['nullable', 'string', 'max:100'],
            'outcomes'       => ['nullable', 'string'],
            'status'         => ['required', 'in:draft,published,archived'],
        ];
    }
}
