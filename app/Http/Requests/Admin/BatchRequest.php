<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'capacity'       => ['required', 'integer', 'min:1'],
            'price'          => ['required', 'integer', 'min:0'], // JPY không có thập phân → integer
            'sale_starts_at' => ['required', 'date'],
            'sale_ends_at'   => ['nullable', 'date', 'after_or_equal:sale_starts_at'], // null = không có hạn đóng
            'status'         => ['required', Rule::in(['scheduled', 'on_sale', 'sold_out', 'closed'])],
        ];
    }
}
