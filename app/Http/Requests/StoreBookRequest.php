<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
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
            'book_code' => ['required', 'string', 'max:32', 'unique:books,book_code'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'isbn' => ['nullable', 'string', 'max:20', 'unique:books,isbn'],
            'description' => ['nullable', 'string'],
            'publisher' => ['nullable', 'string', 'max:255'],
            'publication_year' => ['nullable', 'integer', 'between:1000,'.(now()->year + 1)],
            'edition' => ['nullable', 'string', 'max:255'],
            'total_quantity' => ['required', 'integer', 'between:0,4294967295'],
            'rental_fee' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'author_ids' => ['sometimes', 'array'],
            'author_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('authors', 'id')->whereNull('deleted_at'),
            ],
            'cover_image' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
