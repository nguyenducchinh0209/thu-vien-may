<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Payment;
use App\Models\PaymentQuote;
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

    public function issuePaidReservation(User $staff, int $reservationId, Payment $payment): Loan
    {
        return $this->issue($staff, 0, [], [], $reservationId, $payment);
    }

    /**
     * @param  array<int, array{book_id: int, quantity: int}>  $items
     */
    public function issue(User $staff, int $readerId, array $items, array $payment, ?int $reservationId = null, ?Payment $paidPayment = null): Loan
    {
        return DB::transaction(function () use ($staff, $readerId, $items, $payment, $reservationId, $paidPayment): Loan {
            $reservation = null;
            $snapshotItems = [];
            if ($reservationId !== null) {
                $reservationBookId = Reservation::query()->whereKey($reservationId)->value('book_id');
                if ($reservationBookId === null) {
                    throw ValidationException::withMessages(['reservation_id' => 'Không tìm thấy yêu cầu đặt trước.']);
                }
                Book::query()->whereKey($reservationBookId)->lockForUpdate()->first();
                $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
                if (! $paidPayment && $reservation->payment_status !== null) {
                    throw ValidationException::withMessages([
                        'reservation_id' => 'Yêu cầu này đang dùng quy trình thanh toán; hãy xác nhận khoản thu trước khi giao sách.',
                    ]);
                }
                if ($paidPayment) {
                    $paidPayment = Payment::query()->whereKey($paidPayment->id)->lockForUpdate()->firstOrFail();
                    $existingLoan = Loan::query()->where('payment_id', $paidPayment->id)->lockForUpdate()->first();
                    if ($existingLoan) {
                        return $existingLoan->load(['reader:id,name,email', 'items.book:id,title,book_code']);
                    }
                    if ($paidPayment->reservation_id !== $reservation->id || $paidPayment->status !== 'paid'
                        || $reservation->payment_status !== 'paid') {
                        throw ValidationException::withMessages(['payment' => 'Khoản thanh toán chưa được xác nhận cho yêu cầu này.']);
                    }
                    $quote = PaymentQuote::query()->whereKey($paidPayment->quote_id)->firstOrFail();
                    $snapshotItems = $quote->items_snapshot;
                    if (count($snapshotItems) !== 1
                        || (int) ($snapshotItems[0]['book_id'] ?? 0) !== (int) $reservation->book_id
                        || (int) ($snapshotItems[0]['quantity'] ?? 0) !== (int) $reservation->quantity) {
                        throw ValidationException::withMessages(['payment' => 'Bản chụp báo giá không còn khớp với yêu cầu đặt sách.']);
                    }
                }
                if ($reservation->status !== 'ready' || ! $reservation->expires_at?->isFuture()) {
                    throw ValidationException::withMessages([
                        'reservation_id' => $paidPayment
                            ? 'Khoản đã thu được giữ nguyên nhưng thời hạn giao sách đã hết; cần xử lý thủ công.'
                            : 'Yêu cầu đặt trước không còn hiệu lực.',
                    ]);
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

            if ($paidPayment) {
                $totalCents = $this->moneyCents((string) $paidPayment->amount);
                $receivedCents = $totalCents;
                $receiptMethod = $paidPayment->method;
                $receiptNote = $paidPayment->transfer_snapshot['simulated'] ?? false
                    ? 'Chuyển khoản mô phỏng; đã được thư viện xác nhận.'
                    : null;
                $receivedAt = $paidPayment->confirmed_at;
                $receivedBy = $paidPayment->confirmed_by;
            } else {
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
                $receiptMethod = $payment['method'];
                $receiptNote = $payment['receipt_note'] ?? null;
                $receivedAt = now();
                $receivedBy = $staff->id;
            }

            $issuedAt = now();
            $loan = Loan::create([
                'reader_id' => $readerId,
                'issued_by' => $staff->id,
                'issued_at' => $issuedAt,
                'due_at' => $issuedAt->copy()->addDays(14),
                'status' => 'active',
                'reservation_id' => $reservation?->id,
                'payment_id' => $paidPayment?->id,
                'rental_fee_total' => number_format($totalCents / 100, 2, '.', ''),
                'rental_fee_received' => number_format($receivedCents / 100, 2, '.', ''),
                'rental_fee_received_at' => $receivedAt,
                'rental_fee_received_by' => $receivedBy,
                'rental_fee_payment_method' => $receiptMethod,
                'rental_fee_receipt_note' => $receiptNote,
            ]);

            foreach ($items as $item) {
                $book = $books->get((int) $item['book_id']);
                $snapshotUnitAmount = $paidPayment ? $snapshotItems[0]['unit_amount'] : $book->rental_fee;
                $loan->items()->create([
                    'book_id' => $book->id,
                    'quantity' => $item['quantity'],
                    'rental_fee_per_copy' => $snapshotUnitAmount,
                ]);
            }

            if ($reservation) {
                $reservation->update([
                    'status' => 'fulfilled',
                    'fulfilled_at' => $issuedAt,
                ]);
            }

            return $loan;
        }, 3);
    }

    private function moneyCents(string $value): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid stored monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
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
