<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentQuote;
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
            if ($fine->payments()->exists()
                || Payment::query()->where('fine_id', $fine->id)->where('status', 'paid')->exists()) {
                abort(409, 'Khoản phạt đã được thu. Không thể ghi nhận thêm khoản thanh toán.');
            }
            $attempts = Payment::query()->where('fine_id', $fine->id)
                ->whereIn('status', ['pending', 'confirming'])
                ->latest('created_at')->get(['id', 'quote_id']);
            foreach ($attempts as $candidate) {
                $quote = PaymentQuote::query()->whereKey($candidate->quote_id)->lockForUpdate()->firstOrFail();
                $attempt = Payment::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($attempt->status === 'paid') {
                    abort(409, 'Khoản phạt đã được thu qua giao dịch. Không thể ghi nhận thêm khoản thanh toán.');
                }
                if (! in_array($attempt->status, ['pending', 'confirming'], true)) {
                    continue;
                }
                if ($quote->expires_at->isFuture()) {
                    abort(409, 'Khoản phạt đang có giao dịch thanh toán trực tuyến. Hãy xử lý giao dịch đó trước.');
                }

                $previousStatus = $attempt->status;
                $attempt->update(['status' => 'expired']);
                PaymentEvent::create([
                    'payment_id' => $attempt->id,
                    'actor_id' => null,
                    'from_status' => $previousStatus,
                    'to_status' => 'expired',
                    'event_type' => 'quote_expired',
                    'note' => 'Báo giá khoản phạt đã hết hạn trước khi thu thủ công.',
                    'created_at' => now(),
                ]);
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
