<?php

namespace App\Http\Controllers;

use App\Http\Requests\WaiveFineRequest;
use App\Models\Fine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FineController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $fines = Fine::query()
            ->with(['loan:id,issued_at,due_at', 'returnItem.loanItem.book:id,title,book_code', 'payments.receivedBy:id,name'])
            ->where('reader_id', $request->user()->id)
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 10), 50)));

        return response()->json($fines);
    }

    public function index(Request $request): JsonResponse
    {
        $fines = Fine::query()
            ->with(['reader:id,name,email', 'loan:id,issued_at,due_at', 'returnItem.loanItem.book:id,title,book_code', 'waivedBy:id,name', 'payments.receivedBy:id,name'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('reader_id'), fn ($query) => $query->where('reader_id', $request->integer('reader_id')))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($fines);
    }

    public function waive(WaiveFineRequest $request, Fine $fine): JsonResponse
    {
        DB::transaction(function () use ($request, $fine): void {
            $fine = Fine::query()->whereKey($fine->id)->lockForUpdate()->firstOrFail();
            if ($fine->status !== 'unpaid') {
                throw ValidationException::withMessages(['fine' => 'Chỉ khoản phạt chưa thanh toán mới có thể miễn.']);
            }

            $fine->update([
                'status' => 'waived',
                'waived_by' => $request->user()->id,
                'waiver_reason' => $request->validated('reason'),
                'waived_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Đã miễn khoản phạt.', 'fine' => $fine->fresh()]);
    }
}
