<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanIssuanceService
{
    /**
     * @param  array<int, array{book_id: int, quantity: int}>  $items
     */
    public function issue(User $staff, int $readerId, array $items, ?int $reservationId = null): Loan
    {
        return DB::transaction(function () use ($staff, $readerId, $items, $reservationId): Loan {
            $reservation = null;
            if ($reservationId !== null) {
                $reservationBookId = Reservation::query()->whereKey($reservationId)->value('book_id');
                if ($reservationBookId === null) {
                    throw ValidationException::withMessages(['reservation_id' => 'Không tìm thấy yêu cầu đặt trước.']);
                }
                Book::query()->whereKey($reservationBookId)->lockForUpdate()->first();
                $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
                if ($reservation->status !== 'ready' || ! $reservation->expires_at?->isFuture()) {
                    throw ValidationException::withMessages(['reservation_id' => 'Yêu cầu đặt trước không còn hiệu lực.']);
                }
                $readerId = $reservation->reader_id;
                $items = [['book_id' => $reservation->book_id, 'quantity' => $reservation->quantity]];
            }

            $reader = User::query()
                ->where('role', 'Độc giả')
                ->lockForUpdate()
                ->find($readerId);

            if (! $reader) {
                throw ValidationException::withMessages(['reader_id' => 'Không tìm thấy độc giả đang hoạt động.']);
            }

            $bookIds = collect($items)->pluck('book_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
            $books = Book::query()
                ->whereIn('id', $bookIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($books->count() !== $bookIds->count()) {
                throw ValidationException::withMessages(['items' => 'Có sách không còn trong danh mục hoạt động.']);
            }

            $onLoan = LoanItem::query()
                ->selectRaw('book_id, SUM(quantity - returned_quantity) as outstanding_quantity')
                ->whereIn('book_id', $bookIds)
                ->whereHas('loan', fn (Builder $query) => $query->where('status', 'active'))
                ->groupBy('book_id')
                ->pluck('outstanding_quantity', 'book_id');

            $reserved = $this->activeReservations($bookIds, $reservation?->id);

            foreach ($items as $index => $item) {
                $book = $books->get((int) $item['book_id']);
                $available = $book->total_quantity
                    - (int) $onLoan->get($book->id, 0)
                    - (int) $reserved->get($book->id, 0);

                if ($item['quantity'] > $available) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Chỉ còn {$available} bản có thể cho mượn sau khi tính các yêu cầu đang giữ sách.",
                    ]);
                }
            }

            $issuedAt = now();
            $loan = Loan::create([
                'reader_id' => $readerId,
                'issued_by' => $staff->id,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(14),
                'status' => 'active',
            ]);

            foreach ($items as $item) {
                $book = $books->get((int) $item['book_id']);
                $loan->items()->create([
                    'book_id' => $book->id,
                    'quantity' => $item['quantity'],
                    'rental_fee_per_copy' => $book->rental_fee,
                ]);
            }

            if ($reservation) {
                $reservation->update([
                    'status' => 'fulfilled',
                    'fulfilled_at' => $issuedAt,
                ]);
            }

            return $loan;
        });
    }

    /**
     * @param  Collection<int, int>  $bookIds
     * @return Collection<int, int>
     */
    private function activeReservations(Collection $bookIds, ?int $exceptId = null): Collection
    {
        return Reservation::query()
            ->selectRaw('book_id, SUM(quantity) as reserved_quantity')
            ->whereIn('book_id', $bookIds)
            ->where(function (Builder $query): void {
                $query->where('status', 'pending')
                    ->orWhere(fn (Builder $query) => $query->where('status', 'ready')->where('expires_at', '>', now()));
            })
            ->when($exceptId, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->groupBy('book_id')
            ->pluck('reserved_quantity', 'book_id');
    }
}
