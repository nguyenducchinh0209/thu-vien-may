<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Models\Book;
use App\Models\BookReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    public function index(Book $book): JsonResponse
    {
        $reviews = $book->reviews()
            ->with('user:id,name')
            ->latest()
            ->paginate(10);

        return response()->json([
            'average_rating' => round((float) $book->reviews()->avg('rating'), 2),
            'review_count' => $book->reviews()->count(),
            'reviews' => $reviews,
        ]);
    }

    public function store(StoreReviewRequest $request, Book $book): JsonResponse
    {
        $review = DB::transaction(function () use ($request, $book): BookReview {
            Book::query()->whereKey($book->id)->lockForUpdate()->firstOrFail();
            if (BookReview::query()->where('book_id', $book->id)->where('user_id', $request->user()->id)->exists()) {
                throw ValidationException::withMessages(['rating' => 'Bạn đã đánh giá sách này. Hãy sửa đánh giá hiện có.']);
            }

            return BookReview::create($request->validated() + [
                'book_id' => $book->id,
                'user_id' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Đã gửi đánh giá.',
            'review' => $review->load('user:id,name'),
        ], 201);
    }

    public function update(UpdateReviewRequest $request, BookReview $review): JsonResponse
    {
        abort_unless($review->user_id === $request->user()->id, 404);
        $review->update($request->validated());

        return response()->json(['review' => $review->fresh()->load('user:id,name')]);
    }

    public function destroy(Request $request, BookReview $review): JsonResponse
    {
        abort_unless($review->user_id === $request->user()->id, 404);
        $review->delete();

        return response()->json(['message' => 'Đã xóa đánh giá.']);
    }
}
