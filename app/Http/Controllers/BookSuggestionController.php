<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewBookSuggestionRequest;
use App\Http\Requests\StoreBookSuggestionRequest;
use App\Models\BookSuggestion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookSuggestionController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        return response()->json(BookSuggestion::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(10));
    }

    public function store(StoreBookSuggestionRequest $request): JsonResponse
    {
        $suggestion = BookSuggestion::create($request->validated() + ['user_id' => $request->user()->id]);

        return response()->json([
            'message' => 'Đã gửi đề xuất bổ sung sách.',
            'suggestion' => $suggestion,
        ], 201);
    }

    public function staffIndex(Request $request): JsonResponse
    {
        $suggestions = BookSuggestion::query()
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('q'), fn (Builder $query) => $query->where('title', 'like', '%'.$request->string('q')->trim().'%'))
            ->latest()
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($suggestions);
    }

    public function review(ReviewBookSuggestionRequest $request, BookSuggestion $suggestion): JsonResponse
    {
        $suggestion->update([
            ...$request->validated(),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Đã cập nhật trạng thái đề xuất.',
            'suggestion' => $suggestion->fresh()->load('user:id,name,email', 'reviewer:id,name'),
        ]);
    }
}
