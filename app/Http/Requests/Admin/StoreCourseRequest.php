<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourseRequest extends FormRequest
{
    use NormalizesCourseData;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['nullable', 'string', 'max:255', 'unique:courses,slug'],
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
