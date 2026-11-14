<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoanDueDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'due_at' => ['nullable', 'date', 'after:now'],
            'deposit_payment.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'deposit_payment.method' => ['required', 'string', 'in:cash,bank_transfer,manual'],
            'deposit_payment.receipt_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'due_at.date' => 'Ngày hẹn trả không hợp lệ.',
            'due_at.after' => 'Ngày hẹn trả phải sau thời điểm phát phiếu mượn.',
        ];
    }
}
