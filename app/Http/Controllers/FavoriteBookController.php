<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class FavoriteBookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $books = Book::query()
            ->join('book_favorites', 'books.id', '=', 'book_favorites.book_id')
            ->where('book_favorites.user_id', $request->user()->id)
            ->with('authors:id,name')
            ->orderByDesc('book_favorites.created_at')
            ->orderByDesc('books.id')
            ->get(['books.*'])
            ->each->makeHidden('preview_pages');

        return response()->json(['data' => $books]);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate(['book_id' => ['required', 'integer']]);
        Book::query()->findOrFail($data['book_id']);
        DB::table('book_favorites')->insertOrIgnore([
            'user_id' => $request->user()->id,
            'book_id' => $data['book_id'],
            'created_at' => now(),
        ]);

        return response()->noContent();
    }

    public function destroy(Request $request, int $book): Response
    {
        DB::table('book_favorites')
            ->where('user_id', $request->user()->id)
            ->where('book_id', $book)
            ->delete();

        return response()->noContent();
    }
}
