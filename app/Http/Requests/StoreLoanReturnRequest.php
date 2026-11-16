<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'return_key' => ['nullable', 'string', 'max:80'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.loan_item_id' => ['required', 'integer', 'distinct', 'exists:loan_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
