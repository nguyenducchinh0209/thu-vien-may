<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Http\Requests\LoanDueDateRequest;
use App\Models\Book;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\User;
use App\Services\BookAvailability;
use App\Services\LibraryNotifier;
use App\Services\LoanIssuanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $reservations = Reservation::query()
            ->with(['book:id,title,book_code,cover_image_path', 'approver:id,name'])
            ->where('reader_id', $request->user()->id)
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 10), 50)));

        return response()->json($reservations);
    }

    public function show(Request $request, Reservation $reservation): JsonResponse
    {
        abort_unless($reservation->reader_id === $request->user()->id, 404);

        $reservation->load('book:id,title,book_code');
        $payment = $reservation->payment()->first();
        $loan = Loan::query()->where('reservation_id', $reservation->id)->first();

        $paymentPayload = $payment?->only([
            'id', 'purpose', 'method', 'status', 'amount', 'currency',
            'reported_at', 'confirmed_at',
        ]);
        if ($paymentPayload) {
            $paymentPayload['transfer'] = $payment->transfer_snapshot;
        }

        return response()->json([
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status,
                'quantity' => $reservation->quantity,
                'ready_at' => $reservation->ready_at?->toISOString(),
                'expires_at' => $reservation->expires_at?->toISOString(),
                'payment_status' => $reservation->payment_status,
                'payment_method' => $reservation->payment_method,
                'amount' => $reservation->amount,
                'currency' => $reservation->currency,
                'book' => $reservation->book?->only(['id', 'title', 'book_code']),
            ],
            'payment' => $paymentPayload,
            'loan' => $loan?->only([
                'id', 'status', 'issued_at', 'due_at', 'rental_fee_total', 'rental_fee_received',
            ]),
        ]);
    }

    public function store(StoreReservationRequest $request, LibraryNotifier $notifier): JsonResponse
    {
        $validated = $request->validated();
        $paymentMethod = $request->validate([
            'payment_method' => ['nullable', 'string', 'in:cash,bank_transfer'],
        ])['payment_method'] ?? null;
        $reservation = DB::transaction(function () use ($request, $validated, $paymentMethod): Reservation {
            $book = Book::query()->whereKey($validated['book_id'])->lockForUpdate()->firstOrFail();
            if ($validated['quantity'] > $book->total_quantity) {
                throw ValidationException::withMessages([
                    'quantity' => 'Số lượng đặt trước không thể vượt tổng số bản của đầu sách.',
                ]);
            }

            $hasOpenRequest = Reservation::query()
                ->where('reader_id', $request->user()->id)
                ->where('book_id', $validated['book_id'])
                ->where(function (Builder $query): void {
                    $query->where('status', 'pending')
                        ->orWhere(fn (Builder $query) => $query->where('status', 'ready')->where('expires_at', '>', now()));
                })
                ->exists();

            if ($hasOpenRequest) {
                throw ValidationException::withMessages(['book_id' => 'Bạn đã có yêu cầu đặt trước còn hiệu lực cho sách này.']);
            }

            return Reservation::create([
                'reader_id' => $request->user()->id,
                'book_id' => $validated['book_id'],
                'quantity' => $validated['quantity'],
                'status' => 'pending',
                'payment_status' => $paymentMethod ? 'unpaid' : null,
                'payment_method' => $paymentMethod,
                'currency' => $paymentMethod ? 'VND' : null,
            ]);
        });

        $bookTitle = $reservation->book()->value('title') ?? 'một đầu sách';
        $notifier->librarians(
            'Có yêu cầu đặt sách mới',
            "Độc giả {$request->user()->name} vừa gửi yêu cầu đặt “{$bookTitle}”.",
            'reservations',
        );

        return response()->json([
            'message' => 'Đã gửi yêu cầu đặt trước.',
            'reservation' => $reservation->load('book:id,title,book_code'),
        ], 201);
    }

    public function cancel(Request $request, Reservation $reservation, LibraryNotifier $notifier): JsonResponse
    {
        $expectedStatus = $request->validate([
            'expected_status' => ['nullable', 'in:pending,ready'],
        ])['expected_status'] ?? null;

        DB::transaction(function () use ($request, $reservation, $expectedStatus): void {
            Book::withTrashed()->whereKey($reservation->book_id)->lockForUpdate()->first();
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->reader_id === $request->user()->id, 404);
            abort_unless(in_array($reservation->status, ['pending', 'ready'], true), 409, 'Yêu cầu này không thể hủy.');
            if ($expectedStatus !== null && $reservation->status !== $expectedStatus) {
                abort(409, 'Yêu cầu đã thay đổi trạng thái. Hãy tải lại danh sách trước khi tiếp tục.');
            }

            $payment = Payment::query()->where('reservation_id', $reservation->id)->lockForUpdate()->first();
            if ($reservation->status === 'ready'
                && ($reservation->payment_status === 'paid' || $payment?->status === 'paid')) {
                abort(409, 'Yêu cầu đã được thanh toán và không thể hủy tại đây. Hãy liên hệ thư viện để được hướng dẫn.');
            }

            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

        $bookTitle = $reservation->book()->value('title') ?? 'một đầu sách';
        $notifier->librarians(
            'Độc giả đã hủy yêu cầu đặt sách',
            "Độc giả {$request->user()->name} đã hủy yêu cầu “{$bookTitle}”.",
            'reservations',
        );

        return response()->json(['message' => 'Đã hủy yêu cầu đặt trước.']);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        Reservation::query()
            ->where('status', 'ready')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $reservations = Reservation::query()
            ->with(['reader:id,name,email', 'book:id,title,book_code', 'approver:id,name'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('book_id'), fn (Builder $query) => $query->where('book_id', $request->integer('book_id')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $like = '%'.$search.'%';
                $query->where(function (Builder $row) use ($search, $like): void {
                    $id = ltrim($search, '#');
                    if (ctype_digit($id)) {
                        $row->orWhere('reservations.id', $id);
                    }
                    $row->orWhereHas('book', fn (Builder $book) => $book->where('title', 'like', $like)->orWhere('book_code', 'like', $like))
                        ->orWhereHas('reader', fn (Builder $reader) => $reader->where('name', 'like', $like)->orWhere('email', 'like', $like));
                });
            })
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($reservations);
    }

    public function markReady(Request $request, Reservation $reservation, LibraryNotifier $notifier): JsonResponse
    {
        return $this->approveReservation($request, $reservation, false, $notifier);
    }

    public function approve(Request $request, Reservation $reservation, LibraryNotifier $notifier): JsonResponse
    {
        return $this->approveReservation($request, $reservation, true, $notifier);
    }

    private function approveReservation(Request $request, Reservation $reservation, bool $paymentWorkflow, LibraryNotifier $notifier): JsonResponse
    {
        DB::transaction(function () use ($request, $reservation, $paymentWorkflow): void {
            $reader = User::query()->withTrashed()->whereKey($reservation->reader_id)->lockForUpdate()->first();
            $book = Book::query()->whereKey($reservation->book_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->book_id !== $book->id || $reservation->reader_id !== $reader?->id) {
                throw ValidationException::withMessages(['reservation' => 'Yêu cầu đặt sách đã thay đổi. Tải lại trang rồi thử lại.']);
            }
            if (! $reader || $reader->trashed() || $reader->role !== 'Độc giả' || $reader->is_locked) {
                throw ValidationException::withMessages(['reservation' => 'Không thể duyệt vì tài khoản độc giả không còn hoạt động.']);
            }

            if ($reservation->status !== 'pending') {
                throw ValidationException::withMessages(['reservation' => 'Chỉ yêu cầu đang chờ mới có thể duyệt.']);
            }

            $hasEarlierPending = Reservation::query()
                ->join('users', 'users.id', '=', 'reservations.reader_id')
                ->where('reservations.book_id', $reservation->book_id)
                ->where('reservations.status', 'pending')
                ->whereNull('users.deleted_at')
                ->where('users.role', 'Độc giả')
                ->where('users.is_locked', false)
                ->where('reservations.quantity', '<=', $book->total_quantity)
                ->where(function (Builder $query) use ($reservation): void {
                    $query->where('reservations.created_at', '<', $reservation->created_at)
                        ->orWhere(fn (Builder $query) => $query
                            ->where('reservations.created_at', $reservation->created_at)
                            ->where('reservations.id', '<', $reservation->id));
                })
                ->exists();

            if ($hasEarlierPending) {
                throw ValidationException::withMessages(['reservation' => 'Cần xử lý yêu cầu đặt trước cũ hơn trước.']);
            }

            $available = app(BookAvailability::class)->available($book);

            if ($reservation->quantity > $available) {
                throw ValidationException::withMessages([
                    'reservation' => "Chỉ còn {$available} bản có thể giữ cho yêu cầu này.",
                ]);
            }

            $readyAt = now();
            $unitCents = $this->moneyCents((string) $book->rental_fee);
            $values = [
                'status' => 'ready',
                'approved_by' => $request->user()->id,
                'ready_at' => $readyAt,
                'expires_at' => $readyAt->copy()->addHours(48),
            ];
            if ($paymentWorkflow || $reservation->payment_status !== null) {
                $values += [
                    'payment_status' => 'unpaid',
                    'amount' => $this->formatCents($unitCents * (int) $reservation->quantity),
                    'currency' => 'VND',
                ];
            }
            $reservation->update($values);
        }, 3);

        $updatedReservation = $reservation->fresh()->load(['reader:id,name', 'book:id,title']);
        $notifier->reader(
            $updatedReservation->reader,
            'Yêu cầu đặt sách đã được duyệt',
            "Thủ thư {$request->user()->name} đã duyệt yêu cầu “{$updatedReservation->book?->title}”. Sách được giữ trong 48 giờ.",
            'reservations',
        );

        return response()->json([
            'message' => 'Đã duyệt; sách được giữ trong 48 giờ.',
            'reservation' => $updatedReservation->load(['reader:id,name,email', 'book:id,title,book_code']),
        ]);
    }

    public function fulfill(LoanDueDateRequest $request, Reservation $reservation, LoanIssuanceService $loans, LibraryNotifier $notifier): JsonResponse
    {
        abort_if($reservation->payment_status !== null, 409, 'Yêu cầu đã dùng quy trình thanh toán mới; hãy xác nhận giao dịch rồi dùng chức năng Giao sách.');
        $payment = $request->validate([
            'rental_fee_payment.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'rental_fee_payment.method' => ['required', 'string', 'in:cash,bank_transfer,manual'],
            'rental_fee_payment.receipt_note' => ['nullable', 'string', 'max:500'],
        ])['rental_fee_payment'];
        $deposit = $request->validate([
            'deposit_payment.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'deposit_payment.method' => ['required', 'string', 'in:cash,bank_transfer,manual'],
            'deposit_payment.receipt_note' => ['nullable', 'string', 'max:500'],
        ])['deposit_payment'];
        $loan = $loans->issueReservation($request->user(), $reservation->id, $payment, $request->validated('due_at'), $deposit);
        $loan->load(['reader:id,name,email', 'items.book:id,title,book_code']);
        $bookTitles = $loan->items->pluck('book.title')->filter()->implode('”, “');
        $notifier->reader(
            $loan->reader,
            'Thủ thư đã giao sách',
            "Thủ thư {$request->user()->name} đã phát phiếu mượn và giao sách “{$bookTitles}”. Hạn trả: {$loan->due_at->format('d/m/Y H:i')}.",
            'shelf',
        );

        return response()->json([
            'message' => 'Đã tạo phiếu mượn từ yêu cầu đặt trước.',
            'loan' => $loan,
        ], 201);
    }

    private function moneyCents(string $value): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Invalid book rental fee.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
