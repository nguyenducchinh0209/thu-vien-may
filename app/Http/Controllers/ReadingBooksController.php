<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\ReadingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReadingBooksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membership = Membership::where('user_id', $request->user()->id)->first();
        $vip = $membership?->tier === 'vip' && $membership->status === 'active' && $membership->expires_at?->isFuture();
        $books = ReadingProgress::with('book:id,title,book_code,cover_image_path,epub_path')
            ->where('user_id', $request->user()->id)->latest('last_read_at')->get()
            ->filter(fn (ReadingProgress $progress) => $progress->book?->epub_path && Storage::disk('local')->exists($progress->book->epub_path))
            ->map(function (ReadingProgress $progress) use ($vip): array {
                $book = $progress->book;
                $chapter = $vip ? $progress->last_chapter : min(3, $progress->last_chapter);
                return [
                    'book_id' => $book->id, 'title' => $book->title, 'book_code' => $book->book_code,
                    'cover_image_path' => $book->cover_image_path, 'last_chapter' => $chapter,
                    'last_read_at' => $progress->last_read_at?->toISOString(), 'can_read_full' => $vip,
                    'read_url' => route('books.read', ['book' => $book->id, 'chapter' => $chapter]),
                ];
            })->values();
        return response()->json(['data' => $books]);
    }
}
