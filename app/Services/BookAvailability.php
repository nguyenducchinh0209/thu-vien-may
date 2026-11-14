<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared inventory expressions used by the public catalog and staff inventory. */
class BookAvailability
{
    public function onLoan(): Builder
    {
        return DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->selectRaw('COALESCE(SUM(loan_items.quantity - loan_items.returned_quantity - loan_items.lost_quantity), 0)')
            ->whereColumn('loan_items.book_id', 'books.id')
            ->where('loans.status', 'active');
    }

    public function reserved(): Builder
    {
        return DB::table('reservations')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('reservations.book_id', 'books.id')
            ->where('status', 'ready')
            ->where('expires_at', '>', now());
    }
}
