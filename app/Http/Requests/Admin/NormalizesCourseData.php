<?php

namespace App\Http\Requests\Admin;

use Illuminate\Support\Str;

trait NormalizesCourseData
{
    /**
     * Override validated() thay vì prepareForValidation() vì cần giá trị SAU KHI validate:
     * slug sinh từ title chỉ khi title đã qua rule 'required' — prepareForValidation()
     * chạy trước rules nên title có thể chưa tồn tại.
     * Outcomes từ textarea → mảng (mỗi dòng một mục, dòng trống bỏ qua).
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        $data['slug']          = ($data['slug'] ?? null) ?: Str::slug($data['title']);
        $data['lessons_count'] = $data['lessons_count'] ?? 0;
        $data['outcomes']      = collect(explode("\n", (string) ($data['outcomes'] ?? '')))
            ->map(fn ($l) => trim($l))
            ->filter()
            ->values()
            ->all();

        return $data;
    }
}
