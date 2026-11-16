<?php

namespace App\Services;

use App\Models\Fine;
use App\Models\Loan;
use Illuminate\Support\Collection;

final class LoanDepositBalance
{
    public static function amounts(string $deposit, Collection $fines): array
    {
        $received = LibraryMoney::minor($deposit);
        $due = $fines->sum(fn (Fine $fine): int => LibraryMoney::minor($fine->amount));

        return [
            'deposit' => $received,
            'fines' => $due,
            'balance' => $received - $due,
            'deduction' => min($received, $due),
            'refund' => max(0, $received - $due),
            'additional' => max(0, $due - $received),
        ];
    }

    public static function summary(Loan $loan): array
    {
        $loan->loadMissing(['deposit.settlement', 'items', 'fines.checkoutPayment']);
        $deposit = $loan->deposit;
        if (! $deposit) {
            return ['status' => 'no_deposit', 'label' => 'Không có cọc', 'balance' => null, 'can_settle' => false];
        }

        $unpaid = $loan->fines->where('status', 'unpaid')->values();
        $amounts = self::amounts($deposit->amount, $unpaid);
        $complete = $loan->items->isNotEmpty()
            && $loan->items->every(fn ($item) => $item->returned_quantity + $item->lost_quantity >= $item->quantity);
        $pending = $unpaid->contains(fn (Fine $fine) => in_array($fine->checkoutPayment?->status, ['pending', 'confirming'], true));
        $settlement = $deposit->settlement;
        [$status, $label] = match (true) {
            $settlement !== null => ['settled', 'Đã tất toán'],
            $pending => ['pending_payment', 'Chờ xử lý giao dịch phạt'],
            $amounts['balance'] < 0 => ['payment_due', 'Cần thanh toán'],
            ! $complete => ['deposit_held', 'Đang giữ cọc'],
            $amounts['balance'] > 0 => ['refund_due', 'Chờ hoàn cọc'],
            default => ['ready_to_settle', 'Chờ tất toán'],
        };

        return [
            'status' => $status,
            'label' => $label,
            'deposit' => $deposit->amount,
            'unpaid_fines' => LibraryMoney::decimal($amounts['fines']),
            'balance' => $settlement ? '0.00' : LibraryMoney::decimal($amounts['balance']),
            'can_settle' => $complete && ! $pending && ! $settlement,
            'all_copies_accounted_for' => $complete,
            'pending_fine_payment' => $pending,
            'deposit_deduction' => $settlement?->deposit_used ?? LibraryMoney::decimal($amounts['deduction']),
            'refund' => $settlement?->refund_amount ?? LibraryMoney::decimal($amounts['refund']),
            'additional_due' => $settlement?->additional_received ?? LibraryMoney::decimal($amounts['additional']),
            'settlement' => $settlement,
        ];
    }
}
