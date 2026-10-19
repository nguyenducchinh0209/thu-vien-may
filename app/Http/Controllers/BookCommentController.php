<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookCommentRequest;
use App\Models\Book;
use App\Models\BookComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookCommentController extends Controller
{
    public function index(Book $book): JsonResponse
    {
        return response()->json($book->comments()
            ->with('user:id,name')
            ->latest()
            ->paginate(20));
    }

    public function store(StoreBookCommentRequest $request, Book $book): JsonResponse
    {
        $comment = BookComment::create($request->validated() + [
            'book_id' => $book->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Đã đăng bình luận.',
            'comment' => $comment->load('user:id,name'),
        ], 201);
    }

    public function destroy(Request $request, BookComment $comment): JsonResponse
    {
        abort_unless(
            $comment->user_id === $request->user()->id || $request->user()->role === 'Admin',
            404,
        );
        $comment->delete();

        return response()->json(['message' => 'Đã xóa bình luận.']);
    }
}
