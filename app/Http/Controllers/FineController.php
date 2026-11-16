<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaiveFineRequest;
use App\Models\Fine;
use App\Models\Book;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Payment;
use App\Services\LibraryCalendar;
use App\Services\LibraryMoney;
use App\Services\LibraryNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Builder;

class FineController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $fines = Fine::query()
            ->with(['loan:id,issued_at,due_at', 'loanItem.book:id,title,book_code', 'returnItem.loanItem.book:id,title,book_code', 'payments.receivedBy:id,name'])
            ->where('reader_id', $request->user()->id)
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 10), 50)));

        return response()->json($fines);
    }

    public function index(Request $request): JsonResponse
    {
        $fines = Fine::query()
            ->with(['reader:id,name,email', 'loan:id,issued_at,due_at', 'loanItem.book:id,title,book_code', 'returnItem.loanItem.book:id,title,book_code', 'waivedBy:id,name', 'payments.receivedBy:id,name'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('reader_id'), fn ($query) => $query->where('reader_id', $request->integer('reader_id')))
            ->when($request->filled('q'), function (Builder $query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $like = '%'.$search.'%';
                $query->where(function (Builder $row) use ($search, $like): void {
                    $id = ltrim($search, '#');
                    if (ctype_digit($id)) {
                        $row->orWhere('fines.id', $id)->orWhere('loan_id', $id);
                    }
                    $row->orWhere('fine_code', 'like', $like)->orWhere('reason', 'like', $like)
                        ->orWhereHas('reader', fn (Builder $reader) => $reader->where('name', 'like', $like)->orWhere('email', 'like', $like))
                        ->orWhereHas('loanItem.book', fn (Builder $book) => $book->where('title', 'like', $like)->orWhere('book_code', 'like', $like))
                        ->orWhereHas('returnItem.loanItem.book', fn (Builder $book) => $book->where('title', 'like', $like)->orWhere('book_code', 'like', $like));
                });
            })
            ->latest()->orderByDesc('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($fines);
    }

    public function store(Request $request, LibraryNotifier $notifier): JsonResponse
    {
        $data = $request->validate([
            'loan_item_id' => ['required', 'integer', 'exists:loan_items,id'],
            'type' => ['required', 'in:damage,wet_dirty,missing_parts,unusable,lost,other'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99', 'decimal:0,2'],
            'request_key' => ['required', 'string', 'max:80'],
        ]);
        [$fineId, $created] = DB::transaction(function () use ($request, $data): array {
            $loanId = LoanItem::query()->whereKey($data['loan_item_id'])->value('loan_id');
            $loan = Loan::query()->whereKey($loanId)->lockForUpdate()->firstOrFail();
            $existing = Fine::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                $amount = $data['type'] === 'lost' ? 70000 * $data['quantity'] : (string) $data['amount'];
                abort_unless($existing->loan_item_id === (int) $data['loan_item_id']
                    && $existing->type === $data['type']
                    && (int) $existing->quantity === (int) $data['quantity']
                    && $existing->reason === $data['reason']
                    && LibraryMoney::minor($existing->amount) === LibraryMoney::minor($amount), 409,
                    'Mã gửi lại đã được dùng để lập phiếu phạt khác.');

                return [$existing->id, false];
            }
            $loanItem = $loan->items()->whereKey($data['loan_item_id'])->lockForUpdate()->firstOrFail();
            $book = Book::withTrashed()->whereKey($loanItem->book_id)->lockForUpdate()->firstOrFail();
            if ($loan->status !== 'active' && $data['type'] === 'lost') {
                throw ValidationException::withMessages(['loan_item_id' => 'Không thể ghi mất sách khi phiếu đã kết thúc.']);
            }
            if ($data['type'] === 'lost') {
                $data['amount'] = 70000 * $data['quantity'];
                $available = $loanItem->quantity - $loanItem->returned_quantity - $loanItem->lost_quantity;
                if ($data['quantity'] > $available) throw ValidationException::withMessages(['quantity' => "Chỉ còn {$available} bản có thể xác nhận mất."]);
                $loanItem->increment('lost_quantity', $data['quantity']);
                $book->decrement('total_quantity', $data['quantity']);
                $days = LibraryCalendar::daysOverdue($loan->due_at, now());
                if ($days > 0) {
                    $overdue = Fine::create([
                        'loan_id' => $loan->id, 'loan_item_id' => $loanItem->id, 'reader_id' => $loan->reader_id,
                        'created_by' => $request->user()->id,
                        'type' => 'overdue', 'reason' => "Mất sách sau khi quá hạn {$days} ngày",
                        'overdue_days' => $days, 'daily_rate' => LibraryCalendar::OVERDUE_DAILY_RATE, 'quantity' => $data['quantity'],
                        'amount' => $days * LibraryCalendar::OVERDUE_DAILY_RATE * $data['quantity'], 'status' => 'unpaid',
                    ]);
                    $overdue->update(['fine_code' => 'F'.str_pad((string) $overdue->id, 9, '0', STR_PAD_LEFT)]);
                }
            } elseif ($data['type'] === 'unusable') {
                $available = $loanItem->returned_quantity - $loanItem->written_off_quantity;
                if ($data['quantity'] > $available) throw ValidationException::withMessages(['quantity' => "Chỉ có {$available} bản đã trả để thu hồi khỏi lưu hành."]);
                $loanItem->increment('written_off_quantity', $data['quantity']);
                $book->decrement('total_quantity', $data['quantity']);
            } elseif ($data['quantity'] > $loanItem->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Số bản vi phạm không được vượt số bản trên phiếu mượn.']);
            }
            $fine = Fine::create([
                'loan_id' => $loan->id, 'loan_item_id' => $loanItem->id, 'reader_id' => $loan->reader_id,
                'created_by' => $request->user()->id, 'request_key' => $data['request_key'],
                'type' => $data['type'], 'reason' => $data['reason'], 'quantity' => $data['quantity'],
                'overdue_days' => 0, 'daily_rate' => 0, 'amount' => $data['amount'], 'status' => 'unpaid',
            ]);
            $fine->update(['fine_code' => 'F'.str_pad((string) $fine->id, 9, '0', STR_PAD_LEFT)]);
            if ($data['type'] === 'lost' && $loanItem->returned_quantity + $loanItem->lost_quantity >= $loanItem->quantity) {
                $allComplete = ! $loan->items()->whereRaw('(returned_quantity + lost_quantity) < quantity')->exists();
                if ($allComplete) $loan->update(['status' => 'returned', 'returned_at' => now()]);
            }
            return [$fine->id, true];
        }, 3);
        $fine = Fine::with(['reader', 'loanItem.book', 'createdBy:id,name'])->findOrFail($fineId);
        if ($created) $notifier->reader($fine->reader, 'Thư viện đã lập phiếu phạt', "Phiếu {$fine->fine_code}: {$fine->reason}. Số tiền: ".number_format((float) $fine->amount, 0, ',', '.').' ₫.', 'fines');
        return response()->json(['message' => $created ? 'Đã lập phiếu phạt.' : 'Đã khôi phục phiếu phạt đã phát hành.', 'fine' => $fine], $created ? 201 : 200);
    }

    public function show(Request $request, Fine $fine): JsonResponse
    {
        return response()->json(['fine' => $fine->load(['reader:id,name,email', 'loan', 'loanItem.book', 'returnItem.loanItem.book', 'createdBy:id,name', 'waivedBy:id,name', 'payments.receivedBy:id,name'])]);
    }

    public function waive(WaiveFineRequest $request, Fine $fine, LibraryNotifier $notifier): JsonResponse
    {
        DB::transaction(function () use ($request, $fine): void {
            $fine = Fine::query()->whereKey($fine->id)->lockForUpdate()->firstOrFail();
            if ($fine->status !== 'unpaid') {
                throw ValidationException::withMessages(['fine' => 'Chỉ khoản phạt chưa thanh toán mới có thể miễn.']);
            }
            if (Payment::where('fine_id', $fine->id)->whereIn('status', ['pending', 'confirming'])->exists()) {
                throw ValidationException::withMessages(['fine' => 'Hãy xác nhận hoặc hủy giao dịch đang chờ trước khi miễn phạt.']);
            }

            $fine->update([
                'status' => 'waived',
                'waived_by' => $request->user()->id,
                'waiver_reason' => $request->validated('reason'),
                'waived_at' => now(),
            ]);
        });

        $fine = $fine->fresh()->load('reader:id,name');
        $notifier->reader(
            $fine->reader,
            'Khoản phạt đã được miễn',
            "Quản trị viên {$request->user()->name} đã miễn khoản phạt ".number_format((float) $fine->amount, 0, ',', '.').' ₫ của bạn.',
            'fines',
        );

        return response()->json(['message' => 'Đã miễn khoản phạt.', 'fine' => $fine->fresh()]);
    }
}
