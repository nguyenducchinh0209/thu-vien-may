<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCartItemRequest;
use App\Http\Requests\UpdateCartItemRequest;
use App\Models\Book;
use App\Models\BookCart;
use App\Models\BookCartItem;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReaderCartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'cart' => $this->cartFor($request->user())->load([
                'items.book:id,title,book_code,cover_image_path,total_quantity,rental_fee',
                'items.book.authors:id,name',
            ]),
        ]);
    }

    public function addItem(StoreCartItemRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $cartItem = DB::transaction(function () use ($request, $validated): BookCartItem {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $book = Book::query()->whereKey($validated['book_id'])->lockForUpdate()->firstOrFail();
            $cart = BookCart::firstOrCreate(['user_id' => $user->id]);
            $item = $cart->items()->where('book_id', $validated['book_id'])->lockForUpdate()->first();
            $newQuantity = ($item?->quantity ?? 0) + $validated['quantity'];

            if ($newQuantity > min(20, $book->total_quantity)) {
                throw ValidationException::withMessages(['quantity' => 'Số lượng trong giỏ không thể vượt 20 bản hoặc tổng số bản của đầu sách.']);
            }

            if ($item) {
                $item->update(['quantity' => $newQuantity]);

                return $item;
            }

            return $cart->items()->create([
                'book_id' => $validated['book_id'],
                'quantity' => $validated['quantity'],
            ]);
        });

        return response()->json([
            'message' => 'Đã thêm sách vào giỏ.',
            'item' => $cartItem->load('book:id,title,book_code,cover_image_path,total_quantity'),
        ], 201);
    }

    public function updateItem(UpdateCartItemRequest $request, BookCartItem $item): JsonResponse
    {
        abort_unless($item->cart()->where('user_id', $request->user()->id)->exists(), 404);
        $quantity = $request->validated('quantity');
        DB::transaction(function () use ($request, $item, $quantity): void {
            $lockedItem = BookCartItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedItem->cart()->where('user_id', $request->user()->id)->exists(), 404);
            $book = Book::query()->whereKey($lockedItem->book_id)->lockForUpdate()->first();
            if (! $book) {
                throw ValidationException::withMessages(['book' => 'Sách trong giỏ không còn trong danh mục hoạt động. Vui lòng xóa khỏi giỏ.']);
            }
            if ($quantity > $book->total_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Số lượng trong giỏ không thể vượt tổng số bản của đầu sách.']);
            }
            $lockedItem->update(['quantity' => $quantity]);
        });

        return response()->json(['item' => $item->fresh()->load('book:id,title,book_code,cover_image_path,total_quantity')]);
    }

    public function removeItem(Request $request, BookCartItem $item): JsonResponse
    {
        abort_unless($item->cart()->where('user_id', $request->user()->id)->exists(), 404);
        $item->delete();

        return response()->json(['message' => 'Đã xóa sách khỏi giỏ.']);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->cartFor($request->user());
        $cart->items()->delete();

        return response()->json(['message' => 'Đã làm trống giỏ sách.']);
    }

    public function checkout(Request $request): JsonResponse
    {
        $reservations = DB::transaction(function () use ($request) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $cart = BookCart::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $cart) {
                throw ValidationException::withMessages(['cart' => 'Giỏ sách đang trống.']);
            }

            $items = $cart->items()->orderBy('book_id')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Giỏ sách đang trống.']);
            }

            $books = Book::query()
                ->whereIn('id', $items->pluck('book_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($books->count() !== $items->count()) {
                throw ValidationException::withMessages(['cart' => 'Có sách trong giỏ không còn trong danh mục.']);
            }

            foreach ($items as $index => $item) {
                $book = $books->get($item->book_id);
                if ($item->quantity > $book->total_quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => 'Số lượng trong giỏ vượt tổng số bản của đầu sách.',
                    ]);
                }

                $hasOpenRequest = Reservation::query()
                    ->where('reader_id', $user->id)
                    ->where('book_id', $item->book_id)
                    ->where(function ($query): void {
                        $query->where('status', 'pending')
                            ->orWhere(fn ($query) => $query->where('status', 'ready')->where('expires_at', '>', now()));
                    })
                    ->exists();
                if ($hasOpenRequest) {
                    throw ValidationException::withMessages([
                        "items.{$index}.book_id" => 'Bạn đã có yêu cầu đặt trước còn hiệu lực cho một sách trong giỏ.',
                    ]);
                }
            }

            $created = [];
            foreach ($items as $item) {
                $created[] = Reservation::create([
                    'reader_id' => $user->id,
                    'book_id' => $item->book_id,
                    'quantity' => $item->quantity,
                    'status' => 'pending',
                ]);
            }

            $cart->items()->delete();

            return collect($created)->map(fn (Reservation $reservation) => $reservation->load('book:id,title,book_code'));
        });

        return response()->json([
            'message' => 'Đã gửi các sách trong giỏ thành yêu cầu đặt trước.',
            'reservations' => $reservations,
        ], 201);
    }

    private function cartFor(User $user): BookCart
    {
        return BookCart::firstOrCreate(['user_id' => $user->id]);
    }
}
