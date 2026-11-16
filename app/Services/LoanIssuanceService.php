<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\PaymentQuote;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanIssuanceService
{
    public function __construct(private readonly BookAvailability $availability) {}

    public function issueReservation(User $staff, int $reservationId, array $payment, ?string $dueAt = null, ?array $depositPayment = null): Loan
    {
        return $this->issue($staff, 0, [], $payment, $reservationId, dueAt: $dueAt, depositPayment: $depositPayment);
    }

    public function issuePaidReservation(User $staff, int $reservationId, Payment $payment, ?string $dueAt = null, ?array $depositPayment = null): Loan
    {
        return $this->issue($staff, 0, [], [], $reservationId, $payment, $dueAt, $depositPayment);
    }

    /**
     * @param  array<int, array{book_id: int, quantity: int}>  $items
     */
    public function issue(User $staff, int $readerId, array $items, array $payment, ?int $reservationId = null, ?Payment $paidPayment = null, ?string $dueAt = null, ?array $depositPayment = null, ?string $issuanceKey = null): Loan
    {
        // Lookup immutable keys before opening the transaction so it creates its
        // inventory snapshot only after acquiring the stock locks.
        $reservationInfo = $reservationId === null ? null : Reservation::query()->find($reservationId, ['book_id', 'reader_id']);
        if ($reservationId !== null && ! $reservationInfo) {
            throw ValidationException::withMessages(['reservation_id' => 'Không tìm thấy yêu cầu đặt trước.']);
        }
        $readerId = $reservationInfo?->reader_id ?? $readerId;

        return DB::transaction(function () use ($reservationInfo, $staff, $readerId, $items, $payment, $reservationId, $paidPayment, $dueAt, $depositPayment, $issuanceKey): Loan {
            if (! $depositPayment || (float) ($depositPayment['amount'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['deposit_payment.amount' => 'Nhập số tiền cọc đã thu trước khi giao sách.']);
            }
            $issuanceFingerprint = null;
            if ($issuanceKey !== null && $reservationId === null && $paidPayment === null) {
                $fingerprintData = [
                    'reader_id' => $readerId,
                    'items' => collect($items)->map(fn ($item) => ['book_id' => (int) $item['book_id'], 'quantity' => (int) $item['quantity']])->sortBy('book_id')->values()->all(),
                    'rental_fee_payment' => $payment,
                    'deposit_payment' => $depositPayment,
                    'due_at' => $dueAt,
                ];
                $issuanceFingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
                $existingLoan = Loan::query()->where('issuance_key', $issuanceKey)->lockForUpdate()->first();
                if ($existingLoan) {
                    if (! hash_equals((string) $existingLoan->issuance_fingerprint, $issuanceFingerprint)) {
                        abort(409, 'Khóa tạo phiếu đã được dùng cho yêu cầu khác.');
                    }
                    return $existingLoan->load(['reader:id,name,email', 'items.book:id,title,book_code', 'deposit']);
                }
            }
            $reader = User::query()->whereKey($readerId)->lockForUpdate()->first();
            $reservation = null;
            $snapshotItems = [];
            if ($reservationId !== null) {
                Book::query()->whereKey($reservationInfo->book_id)->lockForUpdate()->first();
                $reservation = Reservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
                abort_unless($reservation->book_id === $reservationInfo->book_id
                    && $reservation->reader_id === $readerId, 409, 'Yêu cầu đặt sách đã thay đổi.');
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
                    $quote = PaymentQuote::query()->whereKey($paidPayment->quote_id)->lockForUpdate()->firstOrFail();
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

            if (! $reader || $reader->role !== 'Độc giả' || $reader->is_locked) {
                throw ValidationException::withMessages(['reader_id' => 'Chọn độc giả đang hoạt động để phát phiếu.']);
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

            $occupied = $this->availability->occupiedFor($bookIds->all(), $reservation?->id);

            foreach ($items as $index => $item) {
                $book = $books->get((int) $item['book_id']);
                $available = max(0, $book->total_quantity - $occupied->get($book->id, 0));

                if ($item['quantity'] > $available) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Chỉ còn {$available} bản có thể cho mượn sau khi tính các yêu cầu đang giữ sách.",
                    ]);
                }
            }

            if ($paidPayment) {
                $totalCents = LibraryMoney::minor((string) $paidPayment->amount);
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
                    $totalCents += LibraryMoney::minor((string) $book->rental_fee) * (int) $item['quantity'];
                }
                $receivedCents = LibraryMoney::minor((string) $payment['amount']);
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
            $dueDate = $dueAt ? Carbon::parse($dueAt)->setTimezone(config('app.timezone')) : $issuedAt->copy()->addDays(14);
            if ($dueDate->lessThanOrEqualTo($issuedAt)) {
                throw ValidationException::withMessages(['due_at' => 'Ngày hẹn trả phải sau thời điểm phát phiếu mượn.']);
            }
            $loan = Loan::create([
                'reader_id' => $readerId,
                'issued_by' => $staff->id,
                'reader_name_snapshot' => $reader->name,
                'issuer_name_snapshot' => $staff->name,
                'issued_at' => $issuedAt,
                'due_at' => $dueDate,
                'status' => 'active',
                'reservation_id' => $reservation?->id,
                'payment_id' => $paidPayment?->id,
                'rental_fee_total' => LibraryMoney::decimal($totalCents),
                'rental_fee_received' => LibraryMoney::decimal($receivedCents),
                'rental_fee_received_at' => $receivedAt,
                'rental_fee_received_by' => $receivedBy,
                'rental_fee_payment_method' => $receiptMethod,
                'rental_fee_receipt_note' => $receiptNote,
                'issuance_key' => $issuanceKey,
                'issuance_fingerprint' => $issuanceFingerprint,
            ]);

            foreach ($items as $item) {
                $book = $books->get((int) $item['book_id']);
                $snapshotUnitAmount = $paidPayment ? $snapshotItems[0]['unit_amount'] : $book->rental_fee;
                $loan->items()->create([
                    'book_id' => $book->id,
                    'quantity' => $item['quantity'],
                    'book_title_snapshot' => $book->title,
                    'book_code_snapshot' => $book->book_code,
                    'rental_fee_per_copy' => $snapshotUnitAmount,
                ]);
            }

            $depositAmount = LibraryMoney::minor((string) $depositPayment['amount']);
            $copyCount = collect($items)->sum(fn (array $item): int => (int) $item['quantity']);
            $expectedDepositAmount = $copyCount * 10000000;
            if ($depositAmount !== $expectedDepositAmount) {
                throw ValidationException::withMessages(['deposit_payment.amount' => 'Tiền cọc phải bằng 100.000đ cho mỗi bản sách.']);
            }

            $loan->deposit()->create([
                'amount' => LibraryMoney::decimal($depositAmount),
                'loss_protection_amount' => LibraryMoney::decimal($copyCount * 7000000),
                'book_holding_amount' => LibraryMoney::decimal($copyCount * 3000000),
                'method' => $depositPayment['method'],
                'receipt_note' => $depositPayment['receipt_note'] ?? null,
                'received_by' => $staff->id,
                'received_at' => $issuedAt,
            ]);

            if ($reservation) {
                $reservation->update([
                    'status' => 'fulfilled',
                    'fulfilled_at' => $issuedAt,
                ]);
            }

            return $loan;
        }, 3);
    }
}
