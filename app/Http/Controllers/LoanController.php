<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\StoreLoanReturnRequest;
use App\Models\Book;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanReturn;
use App\Services\LoanIssuanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->with([
                'reader:id,name,email',
                'reader.readerProfile:id,user_id,reader_code',
                'issuer:id,name',
                'items.book:id,title,book_code',
                'returns.items.loanItem.book:id,title,book_code',
                'returns.items.fine',
            ])
            ->when($request->filled('status'), function (Builder $query) use ($request): void {
                $status = $request->string('status')->toString();
                if ($status === 'overdue') {
                    $query->where('status', 'active')->where('due_at', '<', now());
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $query->where(function (Builder $query) use ($search): void {
                    $query->whereHas('reader', fn (Builder $reader) => $reader
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('items.book', fn (Builder $book) => $book
                            ->where('title', 'like', "%{$search}%")
                            ->orWhere('book_code', 'like', "%{$search}%"));
                });
            })
            ->latest('issued_at')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($loans);
    }

    public function mine(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->with(['issuer:id,name', 'items.book:id,title,book_code', 'returns.items.loanItem.book:id,title,book_code'])
            ->where('reader_id', $request->user()->id)
            ->latest('issued_at')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)));

        return response()->json($loans);
    }

    public function store(StoreLoanRequest $request, LoanIssuanceService $loans): JsonResponse
    {
        $validated = $request->validated();
        $loan = isset($validated['reservation_id'])
            ? $loans->issueReservation($request->user(), (int) $validated['reservation_id'])
            : $loans->issue($request->user(), (int) $validated['reader_id'], $validated['items']);

        return response()->json([
            'message' => 'Tạo phiếu mượn thành công.',
            'loan' => $loan->load([
                'reader:id,name,email',
                'reader.readerProfile:id,user_id,reader_code',
                'issuer:id,name',
                'items.book:id,title,book_code',
            ]),
        ], 201);
    }

    public function show(Loan $loan): JsonResponse
    {
        return response()->json([
            'loan' => $loan->load([
                'reader:id,name,email',
                'reader.readerProfile:id,user_id,reader_code',
                'issuer:id,name',
                'items.book:id,title,book_code',
                'returns.returnedBy:id,name',
                'returns.items.loanItem.book:id,title,book_code',
            ]),
        ]);
    }

    public function returnItems(StoreLoanReturnRequest $request, Loan $loan): JsonResponse
    {
        $validated = $request->validated();
        $returnId = DB::transaction(function () use ($request, $loan, $validated): int {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($lockedLoan->status !== 'active') {
                throw ValidationException::withMessages(['loan' => 'Phiếu mượn này đã được trả hết.']);
            }

            $requestedItems = collect($validated['items']);
            $loanItems = $lockedLoan->items()
                ->whereIn('id', $requestedItems->pluck('loan_item_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($loanItems->count() !== $requestedItems->count()) {
                throw ValidationException::withMessages(['items' => 'Có sách không thuộc phiếu mượn này.']);
            }

            Book::withTrashed()
                ->whereIn('id', $loanItems->pluck('book_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($requestedItems as $index => $item) {
                $loanItem = $loanItems->get((int) $item['loan_item_id']);
                $remaining = $loanItem->quantity - $loanItem->returned_quantity;

                if ($item['quantity'] > $remaining) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Chỉ còn {$remaining} bản chưa trả ở dòng sách này.",
                    ]);
                }
            }

            $returnedAt = now();
            $return = LoanReturn::create([
                'loan_id' => $lockedLoan->id,
                'returned_by' => $request->user()->id,
                'returned_at' => $returnedAt,
            ]);

            foreach ($requestedItems as $item) {
                $loanItem = $loanItems->get((int) $item['loan_item_id']);
                $loanItem->increment('returned_quantity', $item['quantity']);
                $returnItem = $return->items()->create([
                    'loan_item_id' => $loanItem->id,
                    'quantity' => $item['quantity'],
                ]);

                $secondsLate = $lockedLoan->due_at->diffInSeconds($returnedAt, false);
                if ($secondsLate > 0) {
                    $overdueDays = max(1, (int) $lockedLoan->due_at->copy()->startOfDay()->diffInDays($returnedAt->copy()->startOfDay()));
                    $dailyRate = 1000;
                    Fine::create([
                        'loan_id' => $lockedLoan->id,
                        'loan_return_item_id' => $returnItem->id,
                        'reader_id' => $lockedLoan->reader_id,
                        'overdue_days' => $overdueDays,
                        'quantity' => $item['quantity'],
                        'daily_rate' => $dailyRate,
                        'amount' => $overdueDays * $dailyRate * $item['quantity'],
                        'status' => 'unpaid',
                    ]);
                }
            }

            $hasOutstandingItems = $lockedLoan->items()
                ->whereColumn('returned_quantity', '<', 'quantity')
                ->exists();

            if (! $hasOutstandingItems) {
                $lockedLoan->update(['status' => 'returned', 'returned_at' => $returnedAt]);
            }

            return $return->id;
        });

        return response()->json([
            'message' => 'Ghi nhận trả sách thành công.',
            'return' => LoanReturn::with(['items.loanItem.book', 'returnedBy:id,name'])->findOrFail($returnId),
            'loan' => $loan->fresh()->load(['items.book:id,title,book_code']),
            'fines' => Fine::whereHas('returnItem', fn (Builder $query) => $query->where('loan_return_id', $returnId))->get(),
        ]);
    }
}
