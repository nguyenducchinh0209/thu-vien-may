<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicCatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $books = Book::query()
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
            ->orderBy('title')
            ->paginate(max(1, min($request->integer('per_page', 12), 50)))
            ->withQueryString();

        return response()->json($books);
    }

    public function show(Book $book): JsonResponse
    {
        return response()->json([
            'book' => $book->load(['authors:id,name'])->loadAvg('reviews', 'rating')->loadCount('reviews'),
        ]);
    }
}
