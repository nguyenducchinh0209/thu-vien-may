<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Services\BookAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $availability = app(BookAvailability::class);
        $waiting = DB::table('reservations')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('reservations.book_id', 'books.id')
            ->where('status', 'pending');

        $onLoan = $availability->onLoan();
        $held = $availability->reserved();
        $status = $request->string('status')->toString();
        $comparison = $status === 'available' ? '>' : '<=';
        $books = Book::query()
            ->select('books.*')
            ->selectSub($onLoan, 'on_loan_quantity')
            ->selectSub($held, 'reserved_quantity')
            ->selectSub($waiting, 'waiting_quantity')
            ->with('authors:id,name')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('book_code', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%");
                });
            })
            ->when(in_array($status, ['available', 'unavailable'], true), fn ($query) => $query
                ->whereRaw('books.total_quantity '.$comparison.' ('.$onLoan->toSql().') + ('.$held->toSql().')',
                    [...$onLoan->getBindings(), ...$held->getBindings()]))
            ->orderBy('title')->orderBy('id')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        $books->getCollection()->transform(function (Book $book): array {
            $data = $book->toArray();
            $onLoanQuantity = (int) $book->on_loan_quantity;
            $reservedQuantity = (int) $book->reserved_quantity;
            $waitingQuantity = (int) $book->waiting_quantity;
            $data['on_loan_quantity'] = $onLoanQuantity;
            $data['reserved_quantity'] = $reservedQuantity;
            $data['waiting_quantity'] = $waitingQuantity;
            $data['available_quantity'] = max(0, $book->total_quantity - $onLoanQuantity - $reservedQuantity);

            return $data;
        });

        return response()->json($books);
    }
}
