<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Book;
use App\Services\BookAvailability;
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
            $lockedBook = Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('total_quantity', $validated)) {
                if ($validated['total_quantity'] < app(BookAvailability::class)->occupied($lockedBook)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'total_quantity' => 'Tổng số bản không thể thấp hơn số đang mượn và đang được giữ.',
                    ]);
                }
            }
            $lockedBook->update($validated);

            if ($hasAuthorIds) {
                $lockedBook->authors()->sync($authorIds);
            }
        });

        return response()->json([
            'message' => 'Cập nhật sách thành công.',
            'book' => $book->fresh()->load('authors:id,name'),
        ]);
    }

    public function destroy(Book $book): JsonResponse
    {
        DB::transaction(function () use ($book): void {
            $locked = Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();
            if (app(BookAvailability::class)->occupied($locked) > 0
                || DB::table('reservations')->where('book_id', $locked->id)->where('status', 'pending')->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'book' => 'Sách còn bản đang mượn hoặc yêu cầu đặt chưa xử lý.',
                ]);
            }
            $locked->delete();
        }, 3);

        return response()->json(['message' => 'Đã xóa sách.']);
    }
}
