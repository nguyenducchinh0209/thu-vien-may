<?php

namespace App\Http\Controllers;

use App\Models\Fine;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LibraryReceiptController extends Controller
{
    public function loan(Request $request, Loan $loan): View
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['Admin', 'Thủ thư'], true) || (int) $loan->reader_id === (int) $user->id, 404);
        $loan->load(['reader:id,name', 'issuer:id,name', 'items.book:id,title,book_code', 'deposit.receivedBy:id,name',
            'deposit.settlement.settledBy:id,name', 'fines.payments.receivedBy:id,name']);

        return view('library.receipt', ['title' => 'Phiếu mượn', 'loan' => $loan]);
    }

    public function fine(Fine $fine): View
    {
        $fine->load(['reader:id,name', 'loan', 'loanItem.book:id,title,book_code', 'returnItem.loanItem.book:id,title,book_code',
            'createdBy:id,name', 'waivedBy:id,name', 'payments.receivedBy:id,name',
            'depositAllocations.settlement.settledBy:id,name']);

        return view('library.receipt', ['title' => 'Phiếu phạt', 'fine' => $fine]);
    }

    public function payment(Payment $payment): View
    {
        abort_unless($payment->confirmed_at && in_array($payment->status, ['paid', 'refunded'], true), 404);
        $payment->load(['reader:id,name', 'confirmedBy:id,name', 'quote']);
        $refund = DB::table('payment_refunds')->where('payment_id', $payment->id)->first();
        $refundPerson = $refund ? User::withTrashed()->find($refund->refunded_by) : null;

        return view('library.receipt', [
            'title' => 'Biên nhận thu', 'payment' => $payment,
            'refund' => $refund, 'refundPerson' => $refundPerson,
        ]);
    }
}
