<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLoanRequest extends FormRequest
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
            'reservation_id' => ['sometimes', 'required', 'integer', 'exists:reservations,id', 'prohibits:reader_id,items'],
            'reader_id' => [
                'required_without:reservation_id',
                'integer',
                Rule::exists('users', 'id')->where('role', 'Độc giả')->whereNull('deleted_at'),
            ],
            'items' => ['required_without:reservation_id', 'array', 'min:1'],
            'items.*.book_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('books', 'id')->whereNull('deleted_at'),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'rental_fee_payment.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'rental_fee_payment.method' => ['required', 'string', Rule::in(['cash', 'bank_transfer', 'manual'])],
            'rental_fee_payment.receipt_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
