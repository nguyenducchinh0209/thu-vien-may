<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BookAvailability
{
    public function onLoan(): Builder
    {
        return DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->selectRaw('COALESCE(SUM(loan_items.quantity - loan_items.returned_quantity - loan_items.lost_quantity), 0)')
            ->whereColumn('loan_items.book_id', 'books.id')
            ->where('loans.status', 'active');
    }

    public function reserved(?int $exceptReservation = null): Builder
    {
        return DB::table('reservations')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('reservations.book_id', 'books.id')
            ->where('status', 'ready')
            ->where('expires_at', '>', now())
            ->when($exceptReservation !== null, fn (Builder $query) => $query->where('id', '!=', $exceptReservation));
    }

    /** Read after locking the book rows when used to decide a stock mutation. */
    public function occupiedFor(array $bookIds, ?int $exceptReservation = null): Collection
    {
        return DB::table('books')->whereIn('books.id', $bookIds)->select('books.id')
            ->selectSub($this->onLoan(), 'loaned')
            ->selectSub($this->reserved($exceptReservation), 'held')
            ->get()->mapWithKeys(fn ($stock) => [$stock->id => (int) $stock->loaned + (int) $stock->held]);
    }

    public function occupied(Book $book, ?int $exceptReservation = null): int
    {
        return $this->occupiedFor([$book->id], $exceptReservation)->get($book->id, 0);
    }

    public function available(Book $book, ?int $exceptReservation = null): int
    {
        return max(0, $book->total_quantity - $this->occupied($book, $exceptReservation));
    }
}
