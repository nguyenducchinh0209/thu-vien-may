<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookCommentController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookSuggestionController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LibraryPageController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\ReaderCartController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', LibraryPageController::class)->name('library');
Route::get('/catalog/books', [PublicCatalogController::class, 'index'])->name('catalog.books.index');
Route::get('/catalog/books/{book}', [PublicCatalogController::class, 'show'])->name('catalog.books.show');
Route::get('/books/{book}/reviews', [ReviewController::class, 'index'])->name('books.reviews.index');
Route::get('/books/{book}/comments', [BookCommentController::class, 'index'])->name('books.comments.index');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('auth.login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
Route::get('/auth/session', fn () => response()->json([
    'user' => request()->user()?->only(['id', 'name', 'email', 'role']),
]))->middleware('auth')->name('auth.session');
Route::get('/staff', fn () => response()->json(['message' => 'Khu vực nhân viên thư viện.']))
    ->middleware(['auth', 'role:Admin,Thủ thư'])->name('staff.home');

Route::middleware(['auth', 'role:Admin,Thủ thư'])
    ->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::apiResource('books', BookController::class);
        Route::apiResource('authors', AuthorController::class);
        Route::apiResource('readers', ReaderController::class);
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show']);
        Route::post('loans/{loan}/returns', [LoanController::class, 'returnItems'])->name('loans.returns.store');
        Route::get('reservations', [ReservationController::class, 'staffIndex'])->name('reservations.index');
        Route::post('reservations/{reservation}/ready', [ReservationController::class, 'markReady'])->name('reservations.ready');
        Route::post('reservations/{reservation}/fulfill', [ReservationController::class, 'fulfill'])->name('reservations.fulfill');
        Route::get('fines', [FineController::class, 'index'])->name('fines.index');
        Route::patch('fines/{fine}/waive', [FineController::class, 'waive'])->middleware('role:Admin')->name('fines.waive');
        Route::get('book-suggestions', [BookSuggestionController::class, 'staffIndex'])->name('book-suggestions.index');
        Route::patch('book-suggestions/{suggestion}', [BookSuggestionController::class, 'review'])->name('book-suggestions.review');
    });

Route::middleware(['auth', 'role:Độc giả'])
    ->group(function () {
        Route::get('/my/cart', [ReaderCartController::class, 'show'])->name('cart.show');
        Route::post('/my/cart/items', [ReaderCartController::class, 'addItem'])->name('cart.items.store');
        Route::patch('/my/cart/items/{item}', [ReaderCartController::class, 'updateItem'])->name('cart.items.update');
        Route::delete('/my/cart/items/{item}', [ReaderCartController::class, 'removeItem'])->name('cart.items.destroy');
        Route::delete('/my/cart', [ReaderCartController::class, 'clear'])->name('cart.clear');
        Route::post('/my/cart/reservations', [ReaderCartController::class, 'checkout'])->name('cart.reservations.store');
        Route::get('/my/loans', [LoanController::class, 'mine'])->name('loans.mine');
        Route::get('/my/reservations', [ReservationController::class, 'mine'])->name('reservations.mine');
        Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::get('/my/fines', [FineController::class, 'mine'])->name('fines.mine');
        Route::post('/books/{book}/reviews', [ReviewController::class, 'store'])->name('books.reviews.store');
        Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
        Route::post('/books/{book}/comments', [BookCommentController::class, 'store'])->name('books.comments.store');
        Route::delete('/comments/{comment}', [BookCommentController::class, 'destroy'])->name('comments.destroy');
        Route::get('/my/book-suggestions', [BookSuggestionController::class, 'mine'])->name('book-suggestions.mine');
        Route::post('/book-suggestions', [BookSuggestionController::class, 'store'])->name('book-suggestions.store');
    });
