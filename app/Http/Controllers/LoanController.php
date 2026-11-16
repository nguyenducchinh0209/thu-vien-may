<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\StoreLoanReturnRequest;
use App\Models\Book;
use App\Models\Fine;
use App\Models\Loan;
use App\Models\LoanReturn;
use App\Services\LibraryCalendar;
use App\Services\LoanDepositBalance;
use App\Services\LoanIssuanceService;
use App\Services\LibraryNotifier;
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
                'deposit', 'deposit.settlement',
                'fines:id,loan_id,amount,status', 'fines.checkoutPayment',
            ])
            ->when(! $request->boolean('summary'), fn (Builder $query) => $query
                ->with(['returns.items.loanItem.book:id,title,book_code', 'returns.items.fine']))
            ->when($request->filled('status'), function (Builder $query) use ($request): void {
                $status = $request->string('status')->toString();
                $now = now();
                match ($status) {
                    'overdue' => $query->where('status', 'active')->where('due_at', '<', $now),
                    'due_today' => $query->where('status', 'active')->where('due_at', '>=', $now)
                        ->where('due_at', '<', $now->copy()->setTimezone(LibraryCalendar::TIMEZONE)->startOfDay()->addDay()->utc()),
                    'deposit_due' => $query->where('status', 'returned')->whereHas('deposit', fn ($deposit) => $deposit->doesntHave('settlement')),
                    default => $query->where('status', $status),
                };
            })
            ->when($request->filled('q'), fn (Builder $query) => $this->searchLoans($query, $request->string('q')->trim()->toString()))
            ->latest('issued_at')->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        $loans->getCollection()->each(fn (Loan $loan) => $loan->setAttribute('money_summary', LoanDepositBalance::summary($loan)));
        return response()->json($loans);
    }

    public function returns(Request $request): JsonResponse
    {
        $records = LoanReturn::query()
            ->with(['loan.reader:id,name', 'items.loanItem.book:id,title,book_code', 'items.fine'])
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $query->where(function (Builder $row) use ($search): void {
                    $id = ltrim($search, '#');
                    if (ctype_digit($id)) {
                        $row->orWhere('loan_returns.id', $id);
                    }
                    $row->orWhereHas('loan', fn (Builder $loan) => $this->searchLoans($loan, $search));
                });
            })
            ->when($request->string('status')->toString() === 'late', fn (Builder $query) => $query->whereHas('items.fine'))
            ->when($request->string('status')->toString() === 'on_time', fn (Builder $query) => $query->whereDoesntHave('items.fine'))
            ->latest('returned_at')->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))->withQueryString();

        return response()->json($records);
    }

    private function searchLoans(Builder $query, string $search): void
    {
        $query->where(function (Builder $match) use ($search): void {
            $id = ltrim($search, '#');
            if (ctype_digit($id)) {
                $match->orWhere('loans.id', $id);
            }
            $like = '%'.$search.'%';
            $match->orWhere('reader_name_snapshot', 'like', $like)
                ->orWhereHas('reader', fn (Builder $reader) => $reader->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhereHas('readerProfile', fn (Builder $profile) => $profile->where('reader_code', 'like', $like)))
                ->orWhereHas('items', fn (Builder $item) => $item->where('book_title_snapshot', 'like', $like)
                    ->orWhere('book_code_snapshot', 'like', $like)
                    ->orWhereHas('book', fn (Builder $book) => $book->where('title', 'like', $like)->orWhere('book_code', 'like', $like)));
        });
    }

    public function mine(Request $request): JsonResponse
    {
        $loans = Loan::query()
            ->with(['issuer:id,name', 'items.book:id,title,book_code,cover_image_path,rental_fee', 'items.book.authors:id,name', 'returns.items.loanItem.book:id,title,book_code', 'deposit', 'deposit.settlement', 'fines:id,loan_id,amount,status', 'fines.checkoutPayment'])
            ->where('reader_id', $request->user()->id)
            ->latest('issued_at')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)));

        $loans->getCollection()->each(fn (Loan $loan) => $loan->setAttribute('money_summary', LoanDepositBalance::summary($loan)));
        return response()->json($loans);
    }

    public function store(StoreLoanRequest $request, LoanIssuanceService $loans, LibraryNotifier $notifier): JsonResponse
    {
        $validated = $request->validated();
        $payment = $validated['rental_fee_payment'];
        $loan = isset($validated['reservation_id'])
            ? $loans->issueReservation($request->user(), (int) $validated['reservation_id'], $payment, $validated['due_at'] ?? null, $validated['deposit_payment'])
            : $loans->issue($request->user(), (int) $validated['reader_id'], $validated['items'], $payment, dueAt: $validated['due_at'] ?? null, depositPayment: $validated['deposit_payment'], issuanceKey: $validated['issuance_key'] ?? null);
        $created = $loan->wasRecentlyCreated;
        $loan->load([
            'reader:id,name,email',
            'reader.readerProfile:id,user_id,reader_code',
            'issuer:id,name',
            'items.book:id,title,book_code,cover_image_path,rental_fee',
            'items.book.authors:id,name',
            'deposit',
        ]);
        if ($created) {
            $notifier->reader(
                $loan->reader,
                'Thủ thư đã tạo phiếu mượn',
                "Thủ thư {$request->user()->name} đã phát phiếu mượn cho bạn. Hạn trả: {$loan->due_at->format('d/m/Y H:i')}.",
                'shelf',
            );
        }

        return response()->json([
            'message' => $created ? 'Tạo phiếu mượn thành công.' : 'Đã khôi phục phiếu mượn đã phát hành.',
            'loan' => $loan,
        ], $created ? 201 : 200);
    }

    public function showMine(Request $request, Loan $loan): JsonResponse
    {
        abort_unless($loan->reader_id === $request->user()->id, 404);
        $loan->load([
            'issuer:id,name', 'items.book:id,title,book_code,cover_image_path',
            'returns.items.loanItem.book:id,title,book_code', 'deposit.settlement',
            'fines.payments.receivedBy:id,name', 'fines.waivedBy:id,name',
        ]);
        $loan->setAttribute('money_summary', LoanDepositBalance::summary($loan));
        return response()->json(['loan' => $loan]);
    }

    public function show(Loan $loan): JsonResponse
    {
        $loan->load([
                'reader:id,name,email',
                'reader.readerProfile:id,user_id,reader_code',
                'issuer:id,name',
                'items.book:id,title,book_code,cover_image_path,rental_fee',
                'items.book.authors:id,name',
                'deposit.settlement',
                'fines.reader:id,name',
                'fines.loanItem.book:id,title,book_code',
                'fines.createdBy:id,name',
                'fines.payments.receivedBy:id,name',
                'returns.returnedBy:id,name',
                'returns.items.loanItem.book:id,title,book_code',
            ]);
        $loan->setAttribute('money_summary', LoanDepositBalance::summary($loan));
        return response()->json(['loan' => $loan]);
    }

    public function returnItems(StoreLoanReturnRequest $request, Loan $loan, LibraryNotifier $notifier): JsonResponse
    {
        $validated = $request->validated();
        $items = collect($validated['items'])->map(fn (array $item) => [
            'loan_item_id' => (int) $item['loan_item_id'],
            'quantity' => (int) $item['quantity'],
        ])->sortBy('loan_item_id')->values();
        $returnKey = $validated['return_key'] ?? null;
        $fingerprint = hash('sha256', $items->toJson());
        [$returnId, $created] = DB::transaction(function () use ($request, $loan, $items, $returnKey, $fingerprint): array {
            $lockedLoan = Loan::query()->whereKey($loan->id)->lockForUpdate()->firstOrFail();
            if ($returnKey !== null) {
                $existing = $lockedLoan->returns()->where('return_key', $returnKey)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->return_fingerprint, $fingerprint), 409,
                        'Mã gửi lại đã được dùng với nội dung trả sách khác.');

                    return [$existing->id, false];
                }
            }
            if ($lockedLoan->status !== 'active') {
                throw ValidationException::withMessages(['loan' => 'Phiếu mượn này đã được trả hết.']);
            }

            $requestedItems = $items;
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
                $remaining = $loanItem->quantity - $loanItem->returned_quantity - $loanItem->lost_quantity;

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
                'return_key' => $returnKey,
                'return_fingerprint' => $returnKey === null ? null : $fingerprint,
            ]);

            foreach ($requestedItems as $item) {
                $loanItem = $loanItems->get((int) $item['loan_item_id']);
                $loanItem->increment('returned_quantity', $item['quantity']);
                $returnItem = $return->items()->create([
                    'loan_item_id' => $loanItem->id,
                    'quantity' => $item['quantity'],
                ]);

                $overdueDays = LibraryCalendar::daysOverdue($lockedLoan->due_at, $returnedAt);
                if ($overdueDays > 0) {
                    $dailyRate = LibraryCalendar::OVERDUE_DAILY_RATE;
                    $fine = Fine::create([
                        'loan_id' => $lockedLoan->id,
                        'loan_return_item_id' => $returnItem->id,
                        'loan_item_id' => $loanItem->id,
                        'reader_id' => $lockedLoan->reader_id,
                        'type' => 'overdue',
                        'reason' => "Trả trễ {$overdueDays} ngày",
                        'created_by' => $request->user()->id,
                        'overdue_days' => $overdueDays,
                        'quantity' => $item['quantity'],
                        'daily_rate' => $dailyRate,
                        'amount' => $overdueDays * $dailyRate * $item['quantity'],
                        'status' => 'unpaid',
                    ]);
                    $fine->update(['fine_code' => 'F'.str_pad((string) $fine->id, 9, '0', STR_PAD_LEFT)]);
                }
            }

            $hasOutstandingItems = $lockedLoan->items()
                ->whereRaw('(returned_quantity + lost_quantity) < quantity')
                ->exists();

            if (! $hasOutstandingItems) {
                $lockedLoan->update(['status' => 'returned', 'returned_at' => $returnedAt]);
            }

            return [$return->id, true];
        }, 3);

        $returnRecord = LoanReturn::with(['items.loanItem.book', 'returnedBy:id,name'])->findOrFail($returnId);
        $updatedLoan = $loan->fresh()->load(['reader', 'items.book:id,title,book_code']);
        $fines = Fine::whereHas('returnItem', fn (Builder $query) => $query->where('loan_return_id', $returnId))->get();
        $fineTotal = $fines->sum(fn (Fine $fine) => (float) $fine->amount);
        if ($created) {
            $notifier->reader(
                $updatedLoan->reader,
                $fines->isEmpty() ? 'Thư viện đã nhận sách trả' : 'Đã nhận sách và ghi nhận khoản phạt',
                $fines->isEmpty()
                    ? "Thủ thư {$request->user()->name} đã ghi nhận sách trả của bạn."
                    : "Thủ thư {$request->user()->name} đã nhận sách; khoản phạt trễ hạn là ".number_format($fineTotal, 0, ',', '.').' ₫.',
                $fines->isEmpty() ? 'shelf' : 'fines',
            );
        }

        return response()->json([
            'message' => $created ? 'Ghi nhận trả sách thành công.' : 'Lượt trả sách này đã được ghi nhận.',
            'return' => $returnRecord,
            'loan' => $updatedLoan->loadMissing('items.book:id,title,book_code'),
            'fines' => $fines,
        ]);
    }
}
