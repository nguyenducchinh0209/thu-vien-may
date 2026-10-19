<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $onLoan = DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->selectRaw('COALESCE(SUM(loan_items.quantity - loan_items.returned_quantity), 0)')
            ->whereColumn('loan_items.book_id', 'books.id')
            ->where('loans.status', 'active');
        $reserved = DB::table('reservations')
            ->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('reservations.book_id', 'books.id')
            ->where('status', 'ready')
            ->where('expires_at', '>', now());
        $books = Book::query()
            ->select('books.*')
            ->selectSub($onLoan, 'on_loan_quantity')
            ->selectSub($reserved, 'reserved_quantity')
            ->with(['authors:id,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('book_code', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%")
                        ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('category'), function ($query) use ($request): void {
                $category = $request->string('category')->toString();
                $category === '__uncategorized'
                    ? $query->whereNull('category')
                    : $query->where('category', $category);
            })
            ->when(in_array($request->query('sort'), ['title_asc', 'title_desc', 'rental_asc', 'rental_desc'], true), function ($query) use ($request): void {
                match ($request->query('sort')) {
                    'title_desc' => $query->orderByDesc('title'),
                    'rental_asc' => $query->orderBy('rental_fee')->orderBy('title'),
                    'rental_desc' => $query->orderByDesc('rental_fee')->orderBy('title'),
                    default => $query->orderBy('title'),
                };
            }, function ($query) use ($request): void {
                $search = $request->string('q')->trim()->toString();
                if ($search !== '') {
                    $query->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', [$search.'%']);
                }
                $query->orderBy('title');
            })
            ->paginate(max(1, min($request->integer('per_page', 12), 50)))
            ->withQueryString();

        $books->getCollection()->transform(function (Book $book): Book {
            $book->setAttribute('available_quantity', max(
                0,
                $book->total_quantity - (int) $book->on_loan_quantity - (int) $book->reserved_quantity,
            ));
            $book->setAttribute('preview_page_count', count($book->preview_pages ?? []));
            unset($book->preview_pages);

            return $book;
        });

        return response()->json($books->toArray() + [
            'categories' => Book::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function show(Book $book): JsonResponse
    {
        $onLoan = (int) DB::table('loan_items')
            ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
            ->where('loan_items.book_id', $book->id)
            ->where('loans.status', 'active')
            ->sum(DB::raw('loan_items.quantity - loan_items.returned_quantity'));
        $reserved = (int) DB::table('reservations')
            ->where('book_id', $book->id)
            ->where('status', 'ready')
            ->where('expires_at', '>', now())
            ->sum('quantity');
        $book->setAttribute('available_quantity', max(0, $book->total_quantity - $onLoan - $reserved));
        $book->setAttribute('preview_page_count', count($book->preview_pages ?? []));
        unset($book->preview_pages);

        return response()->json([
            'book' => $book->load(['authors:id,name'])->loadAvg('reviews', 'rating')->loadCount('reviews'),
        ]);
    }
}
