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
    public function issueReservation(User $staff, int $reservationId, array $payment): Loan
    {
        return $this->issue($staff, 0, [], $payment, $reservationId);
    }

    /**
     * @param  array<int, array{book_id: int, quantity: int}>  $items
     */
    public function issue(User $staff, int $readerId, array $items, array $payment, ?int $reservationId = null): Loan
    {
        return DB::transaction(function () use ($staff, $readerId, $items, $payment, $reservationId): Loan {
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

            $reserved = $this->activeReservations($bookIds, $reservation?->id, $reservation === null);

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

            $totalCents = 0;
            foreach ($items as $item) {
                $book = $books->get((int) $item['book_id']);
                $totalCents += (int) round((float) $book->rental_fee * 100) * (int) $item['quantity'];
            }
            $receivedCents = (int) round((float) $payment['amount'] * 100);
            if ($receivedCents !== $totalCents) {
                throw ValidationException::withMessages([
                    'rental_fee_payment.amount' => 'Số tiền đã thu phải bằng tổng phí mượn do máy chủ tính.',
                ]);
            }

            $issuedAt = now();
            $loan = Loan::create([
                'reader_id' => $readerId,
                'issued_by' => $staff->id,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(14),
                'status' => 'active',
                'rental_fee_total' => number_format($totalCents / 100, 2, '.', ''),
                'rental_fee_received' => number_format($receivedCents / 100, 2, '.', ''),
                'rental_fee_received_at' => $issuedAt,
                'rental_fee_received_by' => $staff->id,
                'rental_fee_payment_method' => $payment['method'],
                'rental_fee_receipt_note' => $payment['receipt_note'] ?? null,
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
    private function activeReservations(Collection $bookIds, ?int $exceptId = null, bool $includePending = true): Collection
    {
        return Reservation::query()
            ->selectRaw('book_id, SUM(quantity) as reserved_quantity')
            ->whereIn('book_id', $bookIds)
            ->where(function (Builder $query) use ($includePending): void {
                if ($includePending) {
                    $query->where('status', 'pending')->orWhere(function (Builder $query): void {
                        $query->where('status', 'ready')->where('expires_at', '>', now());
                    });
                } else {
                    $query->where('status', 'ready')->where('expires_at', '>', now());
                }
            })
            ->when($exceptId, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->groupBy('book_id')
            ->pluck('reserved_quantity', 'book_id');
    }
}
