<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
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
        $bookId = $this->route('book')->id;

        return [
            'book_code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('books', 'book_code')->ignore($bookId)],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'isbn' => ['sometimes', 'nullable', 'string', 'max:20', Rule::unique('books', 'isbn')->ignore($bookId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'publisher' => ['sometimes', 'nullable', 'string', 'max:255'],
            'publication_year' => ['sometimes', 'nullable', 'integer', 'between:1000,'.(now()->year + 1)],
            'edition' => ['sometimes', 'nullable', 'string', 'max:255'],
            'total_quantity' => ['sometimes', 'required', 'integer', 'between:0,4294967295'],
            'rental_fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'author_ids' => ['sometimes', 'array'],
            'author_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('authors', 'id')->whereNull('deleted_at'),
            ],
            'cover_image' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ];
    }
}
