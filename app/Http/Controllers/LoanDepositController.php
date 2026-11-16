<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanDepositSettlement;
use App\Models\Payment;
use App\Services\LibraryMoney;
use App\Services\LibraryNotifier;
use App\Services\LoanDepositBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanDepositController extends Controller
{
    public function preview(Loan $loan): JsonResponse
    {
        $summary = LoanDepositBalance::summary($loan);
        abort_unless($loan->deposit, 404, 'Phiếu mượn cũ không có tiền cọc.');

        return response()->json([
            ...$summary,
            'loan_id' => $loan->id,
            'ready_to_settle' => $summary['can_settle'],
            'money_status' => $summary['status'],
            'deposit_balance' => $summary['balance'],
            'loss_protection_amount' => $loan->deposit->loss_protection_amount,
            'book_holding_amount' => $loan->deposit->book_holding_amount,
            'unpaid_fines' => $loan->fines->where('status', 'unpaid')->values()
                ->map(fn (Fine $fine) => $fine->only(['id', 'fine_code', 'reason', 'amount'])),
        ]);
    }

    public function settle(Request $request, Loan $loan, LibraryNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'refund_method' => ['nullable', 'in:cash,bank_transfer,manual'],
            'additional_method' => ['nullable', 'in:cash,bank_transfer,manual'],
            'receipt_note' => ['nullable', 'string', 'max:500'],
            'expected_deposit_deduction' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'expected_refund' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'expected_additional_due' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        [$settlementId, $created] = DB::transaction(function () use ($request, $loan, $data): array {
            $loan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            $deposit = $loan->deposit()->lockForUpdate()->first();
            if (! $deposit) throw ValidationException::withMessages(['deposit' => 'Phiếu mượn này không có cọc để tất toán.']);
            $existing = LoanDepositSettlement::where('loan_deposit_id', $deposit->id)->first();
            if ($existing) {
                $this->assertExpectedAmounts($data, [
                    'expected_deposit_deduction' => LibraryMoney::minor($existing->deposit_used),
                    'expected_refund' => LibraryMoney::minor($existing->refund_amount),
                    'expected_additional_due' => LibraryMoney::minor($existing->additional_received),
                ]);

                return [$existing->id, false];
            }
            if ($loan->items()->whereRaw('(returned_quantity + lost_quantity) < quantity')->exists()) {
                throw ValidationException::withMessages(['loan' => 'Chỉ tất toán sau khi đã nhận trả hoặc xác nhận mất toàn bộ số bản.']);
            }
            $fines = Fine::query()->where('loan_id', $loan->id)->where('status', 'unpaid')->orderBy('id')->lockForUpdate()->get();
            if ($fines->isNotEmpty() && Payment::whereIn('fine_id', $fines->pluck('id'))->whereIn('status', ['pending', 'confirming'])->exists()) {
                throw ValidationException::withMessages(['payments' => 'Khoản phạt đang có giao dịch chờ xử lý; hãy xác nhận hoặc hủy giao dịch trước.']);
            }
            $amounts = LoanDepositBalance::amounts($deposit->amount, $fines);
            $usedCents = $amounts['deduction'];
            $refundCents = $amounts['refund'];
            $additionalCents = $amounts['additional'];
            $this->assertExpectedAmounts($data, [
                'expected_deposit_deduction' => $usedCents,
                'expected_refund' => $refundCents,
                'expected_additional_due' => $additionalCents,
            ]);
            if ($refundCents > 0 && empty($data['refund_method'])) throw ValidationException::withMessages(['refund_method' => 'Chọn phương thức hoàn tiền cọc.']);
            if ($additionalCents > 0 && empty($data['additional_method'])) throw ValidationException::withMessages(['additional_method' => 'Chọn phương thức thu thêm tiền phạt.']);
            $settlement = LoanDepositSettlement::create([
                'loan_deposit_id' => $deposit->id,
                'deposit_used' => LibraryMoney::decimal($usedCents),
                'refund_amount' => LibraryMoney::decimal($refundCents),
                'refund_method' => $refundCents ? $data['refund_method'] : null,
                'additional_received' => LibraryMoney::decimal($additionalCents),
                'additional_method' => $additionalCents ? $data['additional_method'] : null,
                'receipt_note' => $data['receipt_note'] ?? null, 'settled_by' => $request->user()->id, 'settled_at' => now(),
            ]);
            $leftDeposit = $usedCents;
            foreach ($fines as $fine) {
                $fineCentsForRow = LibraryMoney::minor($fine->amount);
                $fromDeposit = min($leftDeposit, $fineCentsForRow);
                $leftDeposit -= $fromDeposit;
                $fromAdditional = $fineCentsForRow - $fromDeposit;
                $settlement->allocations()->create([
                    'fine_id' => $fine->id,
                    'deposit_amount' => LibraryMoney::decimal($fromDeposit),
                    'additional_amount' => LibraryMoney::decimal($fromAdditional),
                ]);
                $receiptPrefix = 'Đối soát cọc phiếu mượn #'.$loan->id;
                $receiptNote = $data['receipt_note'] ?? null;
                $paymentNote = $receiptNote
                    ? $receiptPrefix.': '.mb_substr($receiptNote, 0, 500 - mb_strlen($receiptPrefix) - 2)
                    : $receiptPrefix;
                $fine->payments()->create([
                    'received_by' => $request->user()->id, 'amount' => LibraryMoney::decimal($fineCentsForRow),
                    'method' => $fromAdditional > 0 ? $data['additional_method'] : 'manual',
                    'receipt_note' => $paymentNote, 'paid_at' => now(),
                ]);
                $fine->update(['status' => 'paid', 'paid_by' => $request->user()->id, 'paid_at' => now()]);
            }
            return [$settlement->id, true];
        }, 3);
        $settlement = LoanDepositSettlement::with(['deposit.loan.reader', 'settledBy:id,name', 'allocations.fine'])->findOrFail($settlementId);
        if ($created) $notifier->reader($settlement->deposit->loan->reader, 'Đã đối soát tiền cọc', 'Thư viện đã hoàn tất đối soát cọc cho phiếu mượn #'.$settlement->deposit->loan_id.'.', 'shelf');
        return response()->json(['message' => $created ? 'Đã tất toán cọc.' : 'Đối soát cọc đã được hoàn tất trước đó.', 'settlement' => $settlement]);
    }

    private function assertExpectedAmounts(array $data, array $actual): void
    {
        foreach ($actual as $field => $amount) {
            if (isset($data[$field]) && LibraryMoney::minor((string) $data[$field]) !== $amount) {
                abort(409, 'Số liệu cọc hoặc tiền phạt đã thay đổi. Hãy tải lại đối soát.');
            }
        }
    }
}
