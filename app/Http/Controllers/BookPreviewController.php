<?php

namespace App\Http\Controllers;

use App\Models\Book;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookPreviewController extends Controller
{
    public function show(Book $book, int $page): StreamedResponse
    {
        $pages = $book->preview_pages ?? [];
        abort_unless($page >= 1 && $page <= min(3, count($pages)), 404);
        abort_unless(Storage::disk('local')->exists($pages[$page - 1]), 404);

        return Storage::disk('local')->response($pages[$page - 1], basename($pages[$page - 1]), [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:3'],
            'pages.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [
            'pages.max' => 'Bản đọc thử chỉ được có tối đa 3 trang ảnh.',
            'pages.*.image' => 'Mỗi trang phải là một ảnh hợp lệ.',
        ]);

        $stored = [];
        try {
            foreach ($validated['pages'] as $page) {
                $stored[] = $page->store('book-previews/'.$book->id, 'local');
            }
            $previous = $book->preview_pages ?? [];
            $book->update(['preview_pages' => $stored]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($stored);
            throw $exception;
        }

        Storage::disk('local')->delete($previous);

        return response()->json([
            'message' => 'Đã cập nhật bản đọc thử.',
            'preview_page_count' => count($stored),
        ]);
    }

    public function destroy(Book $book): JsonResponse
    {
        $previous = $book->preview_pages ?? [];
        $book->update(['preview_pages' => null]);
        Storage::disk('local')->delete($previous);

        return response()->json(['message' => 'Đã xóa bản đọc thử.']);
    }
}
