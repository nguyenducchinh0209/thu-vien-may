<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinePaymentController extends Controller
{
    public function store(Request $request, Fine $fine): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'method' => ['sometimes', 'required', 'in:cash,bank_transfer,manual'],
            'receipt_note' => ['nullable', 'string', 'max:500'],
        ]);

        $paymentId = DB::transaction(function () use ($request, $fine, $data): int {
            $fine = Fine::query()->whereKey($fine->id)->lockForUpdate()->firstOrFail();
            if ($fine->status !== 'unpaid') {
                throw ValidationException::withMessages(['fine' => 'Khoản phạt này không còn ở trạng thái chờ thu.']);
            }
            if (number_format((float) $data['amount'], 2, '.', '') !== number_format((float) $fine->amount, 2, '.', '')) {
                throw ValidationException::withMessages(['amount' => 'Số tiền thu phải bằng chính xác số tiền khoản phạt.']);
            }
            $paidAt = now();
            $payment = $fine->payments()->create([
                'received_by' => $request->user()->id,
                'amount' => $data['amount'],
                'method' => $data['method'] ?? 'manual',
                'receipt_note' => $data['receipt_note'] ?? null,
                'paid_at' => $paidAt,
            ]);
            $fine->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => $paidAt]);

            return $payment->id;
        });

        return response()->json([
            'message' => 'Đã ghi nhận thu phạt.',
            'payment' => $fine->payments()->with('receivedBy:id,name')->findOrFail($paymentId),
            'fine' => $fine->fresh(),
        ], 201);
    }
}
