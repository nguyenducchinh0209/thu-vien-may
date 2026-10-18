<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAuthorRequest;
use App\Http\Requests\UpdateAuthorRequest;
use App\Models\Author;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $authors = Author::query()
            ->withCount('books')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->trim().'%'))
            ->orderBy('name')
            ->paginate(max(1, min($request->integer('per_page', 10), 50)))
            ->withQueryString();

        return response()->json($authors);
    }

    public function store(StoreAuthorRequest $request): JsonResponse
    {
        $author = Author::create($request->validated());

        return response()->json([
            'message' => 'Tạo tác giả thành công.',
            'author' => $author,
        ], 201);
    }

    public function show(Author $author): JsonResponse
    {
        return response()->json(['author' => $author->load('books')]);
    }

    public function update(UpdateAuthorRequest $request, Author $author): JsonResponse
    {
        $author->update($request->validated());

        return response()->json([
            'message' => 'Cập nhật tác giả thành công.',
            'author' => $author->fresh(),
        ]);
    }

    public function destroy(Author $author): JsonResponse
    {
        $author->delete();

        return response()->json(['message' => 'Đã xóa tác giả.']);
    }
}
