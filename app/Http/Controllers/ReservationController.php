<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Models\Book;
use App\Models\LoanItem;
use App\Models\Reservation;
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

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $reservation = DB::transaction(function () use ($request, $validated): Reservation {
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
            ]);
        });

        return response()->json([
            'message' => 'Đã gửi yêu cầu đặt trước.',
            'reservation' => $reservation->load('book:id,title,book_code'),
        ], 201);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        DB::transaction(function () use ($request, $reservation): void {
            Book::withTrashed()->whereKey($reservation->book_id)->lockForUpdate()->first();
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->reader_id === $request->user()->id, 404);
            abort_unless(in_array($reservation->status, ['pending', 'ready'], true), 409, 'Yêu cầu này không thể hủy.');

            $reservation->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
        });

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
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($reservations);
    }

    public function markReady(Request $request, Reservation $reservation): JsonResponse
    {
        DB::transaction(function () use ($request, $reservation): void {
            Book::query()->whereKey($reservation->book_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            if ($reservation->status !== 'pending') {
                throw ValidationException::withMessages(['reservation' => 'Chỉ yêu cầu đang chờ mới có thể duyệt.']);
            }

            $hasEarlierPending = Reservation::query()
                ->where('book_id', $reservation->book_id)
                ->where('status', 'pending')
                ->where(function (Builder $query) use ($reservation): void {
                    $query->where('created_at', '<', $reservation->created_at)
                        ->orWhere(fn (Builder $query) => $query
                            ->where('created_at', $reservation->created_at)
                            ->where('id', '<', $reservation->id));
                })
                ->exists();

            if ($hasEarlierPending) {
                throw ValidationException::withMessages(['reservation' => 'Cần xử lý yêu cầu đặt trước cũ hơn trước.']);
            }

            $book = Book::query()->findOrFail($reservation->book_id);
            $onLoan = (int) LoanItem::query()
                ->where('book_id', $book->id)
                ->whereHas('loan', fn (Builder $query) => $query->where('status', 'active'))
                ->sum(DB::raw('quantity - returned_quantity'));
            $reserved = (int) Reservation::query()
                ->where('book_id', $book->id)
                ->where('status', 'ready')
                ->where('expires_at', '>', now())
                ->sum('quantity');
            $available = $book->total_quantity - $onLoan - $reserved;

            if ($reservation->quantity > $available) {
                throw ValidationException::withMessages([
                    'reservation' => "Chỉ còn {$available} bản có thể giữ cho yêu cầu này.",
                ]);
            }

            $readyAt = now();
            $reservation->update([
                'status' => 'ready',
                'approved_by' => $request->user()->id,
                'ready_at' => $readyAt,
                'expires_at' => $readyAt->copy()->addHours(48),
            ]);
        });

        return response()->json([
            'message' => 'Đã duyệt; sách được giữ trong 48 giờ.',
            'reservation' => $reservation->fresh()->load(['reader:id,name,email', 'book:id,title,book_code']),
        ]);
    }

    public function fulfill(Request $request, Reservation $reservation, LoanIssuanceService $loans): JsonResponse
    {
        $payment = $request->validate([
            'rental_fee_payment.amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'rental_fee_payment.method' => ['required', 'string', 'in:cash,bank_transfer,manual'],
            'rental_fee_payment.receipt_note' => ['nullable', 'string', 'max:500'],
        ])['rental_fee_payment'];
        $loan = $loans->issueReservation($request->user(), $reservation->id, $payment);

        return response()->json([
            'message' => 'Đã tạo phiếu mượn từ yêu cầu đặt trước.',
            'loan' => $loan->load(['reader:id,name,email', 'items.book:id,title,book_code']),
        ], 201);
    }
}
