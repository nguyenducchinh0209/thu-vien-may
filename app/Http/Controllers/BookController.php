<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $books = Book::query()
            ->with('authors:id,name')
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = $request->string('q')->trim()->toString();

                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('book_code', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%")
                        ->orWhere('publisher', 'like', "%{$search}%")
                        ->orWhereHas('authors', fn ($authors) => $authors->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('author_id'), fn ($query) => $query->whereHas(
                'authors',
                fn ($authors) => $authors->whereKey($request->integer('author_id')),
            ))
            ->orderBy('title')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($books);
    }

    public function store(StoreBookRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $authorIds = $validated['author_ids'] ?? [];
        unset($validated['author_ids']);

        if ($request->hasFile('cover_image')) {
            $validated['cover_image_path'] = $request->file('cover_image')->store('book-covers', 'public');
        }
        unset($validated['cover_image']);

        $book = DB::transaction(function () use ($validated, $authorIds): Book {
            $book = Book::create($validated);
            $book->authors()->sync($authorIds);

            return $book;
        });

        return response()->json([
            'message' => 'Tạo sách thành công.',
            'book' => $book->load('authors:id,name'),
        ], 201);
    }

    public function show(Book $book): JsonResponse
    {
        return response()->json(['book' => $book->load('authors:id,name')]);
    }

    public function update(UpdateBookRequest $request, Book $book): JsonResponse
    {
        $validated = $request->validated();
        $hasAuthorIds = array_key_exists('author_ids', $validated);
        $authorIds = $validated['author_ids'] ?? [];
        unset($validated['author_ids']);

        if ($request->hasFile('cover_image')) {
            $validated['cover_image_path'] = $request->file('cover_image')->store('book-covers', 'public');
        }
        unset($validated['cover_image']);

        DB::transaction(function () use ($book, $validated, $authorIds, $hasAuthorIds): void {
            $book->update($validated);

            if ($hasAuthorIds) {
                $book->authors()->sync($authorIds);
            }
        });

        return response()->json([
            'message' => 'Cập nhật sách thành công.',
            'book' => $book->fresh()->load('authors:id,name'),
        ]);
    }

    public function destroy(Book $book): JsonResponse
    {
        $book->delete();

        return response()->json(['message' => 'Đã xóa sách.']);
    }
}
