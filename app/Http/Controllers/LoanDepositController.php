<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanDepositSettlement;
use App\Models\Payment;
use App\Services\LibraryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanDepositController extends Controller
{
    public function preview(Loan $loan): JsonResponse
    {
        $loan->loadMissing(['deposit.settlement', 'items']);
        abort_unless($loan->deposit, 404, 'Phiếu mượn cũ không có tiền cọc.');
        return response()->json($this->summary($loan));
    }

    public function settle(Request $request, Loan $loan, LibraryNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'refund_method' => ['nullable', 'in:cash,bank_transfer,manual'],
            'additional_method' => ['nullable', 'in:cash,bank_transfer,manual'],
            'receipt_note' => ['nullable', 'string', 'max:500'],
        ]);
        [$settlementId, $created] = DB::transaction(function () use ($request, $loan, $data): array {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $deposit = $loan->deposit()->lockForUpdate()->first();
            if (! $deposit) throw ValidationException::withMessages(['deposit' => 'Phiếu mượn này không có cọc để tất toán.']);
            $existing = LoanDepositSettlement::where('loan_deposit_id', $deposit->id)->first();
            if ($existing) return [$existing->id, false];
            if ($loan->items()->whereRaw('(returned_quantity + lost_quantity) < quantity')->exists()) {
                throw ValidationException::withMessages(['loan' => 'Chỉ tất toán sau khi đã nhận trả hoặc xác nhận mất toàn bộ số bản.']);
            }
            $fines = Fine::query()->where('loan_id', $loan->id)->where('status', 'unpaid')->orderBy('id')->lockForUpdate()->get();
            if ($fines->isNotEmpty() && Payment::whereIn('fine_id', $fines->pluck('id'))->whereIn('status', ['pending', 'confirming'])->exists()) {
                throw ValidationException::withMessages(['payments' => 'Khoản phạt đang có giao dịch chờ xử lý; hãy xác nhận hoặc hủy giao dịch trước.']);
            }
            $fineCents = $fines->sum(fn (Fine $fine) => (int) round((float) $fine->amount * 100));
            $depositCents = (int) round((float) $deposit->amount * 100);
            $usedCents = min($depositCents, $fineCents);
            $refundCents = max(0, $depositCents - $fineCents);
            $additionalCents = max(0, $fineCents - $depositCents);
            if ($refundCents > 0 && empty($data['refund_method'])) throw ValidationException::withMessages(['refund_method' => 'Chọn phương thức hoàn tiền cọc.']);
            if ($additionalCents > 0 && empty($data['additional_method'])) throw ValidationException::withMessages(['additional_method' => 'Chọn phương thức thu thêm tiền phạt.']);
            $settlement = LoanDepositSettlement::create([
                'loan_deposit_id' => $deposit->id,
                'deposit_used' => number_format($usedCents / 100, 2, '.', ''),
                'refund_amount' => number_format($refundCents / 100, 2, '.', ''),
                'refund_method' => $refundCents ? $data['refund_method'] : null,
                'additional_received' => number_format($additionalCents / 100, 2, '.', ''),
                'additional_method' => $additionalCents ? $data['additional_method'] : null,
                'receipt_note' => $data['receipt_note'] ?? null, 'settled_by' => $request->user()->id, 'settled_at' => now(),
            ]);
            $leftDeposit = $usedCents;
            foreach ($fines as $fine) {
                $fineCentsForRow = (int) round((float) $fine->amount * 100);
                $fromDeposit = min($leftDeposit, $fineCentsForRow);
                $leftDeposit -= $fromDeposit;
                $fromAdditional = $fineCentsForRow - $fromDeposit;
                $settlement->allocations()->create([
                    'fine_id' => $fine->id,
                    'deposit_amount' => number_format($fromDeposit / 100, 2, '.', ''),
                    'additional_amount' => number_format($fromAdditional / 100, 2, '.', ''),
                ]);
                $fine->payments()->create([
                    'received_by' => $request->user()->id, 'amount' => number_format($fineCentsForRow / 100, 2, '.', ''),
                    'method' => $fromAdditional > 0 ? $data['additional_method'] : 'manual',
                    'receipt_note' => 'Đối soát cọc phiếu mượn #'.$loan->id.(($data['receipt_note'] ?? null) ? ': '.$data['receipt_note'] : ''), 'paid_at' => now(),
                ]);
                $fine->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now()]);
            }
            return [$settlement->id, true];
        }, 3);
        $settlement = LoanDepositSettlement::with(['deposit.loan.reader', 'settledBy:id,name', 'allocations.fine'])->findOrFail($settlementId);
        if ($created) $notifier->reader($settlement->deposit->loan->reader, 'Đã đối soát tiền cọc', 'Thư viện đã hoàn tất đối soát cọc cho phiếu mượn #'.$settlement->deposit->loan_id.'.', 'shelf');
        return response()->json(['message' => $created ? 'Đã tất toán cọc.' : 'Đối soát cọc đã được hoàn tất trước đó.', 'settlement' => $settlement]);
    }

    private function summary(Loan $loan): array
    {
        $complete = ! $loan->items()->whereRaw('(returned_quantity + lost_quantity) < quantity')->exists();
        $fines = Fine::where('loan_id', $loan->id)->where('status', 'unpaid')->get();
        $pending = Payment::whereIn('fine_id', $fines->pluck('id'))->whereIn('status', ['pending', 'confirming'])->exists();
        $depositCents = (int) round((float) $loan->deposit->amount * 100);
        $fineCents = $fines->sum(fn (Fine $fine) => (int) round((float) $fine->amount * 100));
        return [
            'loan_id' => $loan->id, 'ready_to_settle' => $complete && ! $pending,
            'all_copies_accounted_for' => $complete, 'pending_fine_payment' => $pending,
            'deposit' => $loan->deposit->amount, 'unpaid_fines' => $fines,
            'deposit_deduction' => number_format(min($depositCents, $fineCents) / 100, 2, '.', ''),
            'refund' => number_format(max(0, $depositCents - $fineCents) / 100, 2, '.', ''),
            'additional_due' => number_format(max(0, $fineCents - $depositCents) / 100, 2, '.', ''),
            'settlement' => $loan->deposit->settlement,
        ];
    }
}
