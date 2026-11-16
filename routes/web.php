<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthorController;
use App\Http\Controllers\BookCommentController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookPreviewController;
use App\Http\Controllers\BookSuggestionController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\FinePaymentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LibraryPageController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanDepositController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\FavoriteBookController;
use App\Http\Controllers\LibraryReceiptController;
use App\Http\Controllers\PaymentRefundController;
use App\Http\Controllers\StaffReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\ReaderCartController;
use App\Http\Controllers\ReaderController;
use App\Http\Controllers\ReadingBooksController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SupportChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', LibraryPageController::class)->name('library');
Route::get('/catalog/books', [PublicCatalogController::class, 'index'])->name('catalog.books.index');
Route::get('/membership/plans', [MembershipController::class, 'plans'])->name('membership.plans.index');
Route::get('/catalog/books/{book}', [PublicCatalogController::class, 'show'])->name('catalog.books.show');
Route::get('/catalog/books/{book}/preview/{page}', [BookPreviewController::class, 'show'])->whereNumber('page')->name('catalog.books.preview');
Route::get('/books/{book}/reviews', [ReviewController::class, 'index'])->name('books.reviews.index');
Route::get('/books/{book}/comments', [BookCommentController::class, 'index'])->name('books.comments.index');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('auth.login');
Route::post('/auth/forgot-password', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:5,1')->name('auth.password.email');
Route::get('/password/reset/{token}', fn (string $token) => view('auth.reset-password', ['token' => $token, 'email' => request('email')]))->name('password.reset');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
Route::post('/support/chat', SupportChatController::class)->middleware('throttle:10,1')->name('support.chat');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
Route::get('/auth/session', fn () => response()->json([
    'user' => request()->user()?->only(['id', 'name', 'email', 'role']),
]))->middleware(['auth', 'active'])->name('auth.session');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/receipts/loans/{loan}', [LibraryReceiptController::class, 'loan'])->name('receipts.loans.show');
    Route::get('/my/profile', [AuthController::class, 'profile'])->name('profile.show');
    Route::patch('/my/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::get('/my/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/my/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::post('/my/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
});
Route::delete('/comments/{comment}', [BookCommentController::class, 'destroy'])
    ->middleware('auth')->name('comments.destroy');
Route::get('/staff', fn () => response()->json(['message' => 'Khu vực nhân viên thư viện.']))
    ->middleware(['auth', 'role:Admin,Thủ thư'])->name('staff.home');

Route::middleware(['auth', 'active', 'role:Admin,Thủ thư'])
    ->prefix('staff')
    ->name('staff.')
    ->group(function () {
        Route::get('dashboard', [StaffReportController::class, 'dashboard'])->name('dashboard');
        Route::get('reports', [StaffReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [StaffReportController::class, 'export'])->name('reports.export');
        Route::get('receipts/fines/{fine}', [LibraryReceiptController::class, 'fine'])->name('receipts.fines.show');
        Route::get('receipts/payments/{payment}', [LibraryReceiptController::class, 'payment'])->name('receipts.payments.show');
        Route::post('payments/{payment}/refund', [PaymentRefundController::class, 'store'])->middleware('role:Admin')->name('payments.refund');
        Route::apiResource('books', BookController::class);
        Route::post('books/{book}/preview', [BookPreviewController::class, 'store'])->name('books.preview.store');
        Route::delete('books/{book}/preview', [BookPreviewController::class, 'destroy'])->name('books.preview.destroy');
        Route::apiResource('authors', AuthorController::class);
        Route::apiResource('readers', ReaderController::class);
        Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'update'])->middleware('role:Admin');
        Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('returns', [LoanController::class, 'returns'])->name('returns.index');
        Route::apiResource('loans', LoanController::class)->only(['index', 'store', 'show']);
        Route::post('loans/{loan}/returns', [LoanController::class, 'returnItems'])->name('loans.returns.store');
        Route::get('loans/{loan}/deposit/settlement', [LoanDepositController::class, 'preview'])->name('loans.deposit.preview');
        Route::post('loans/{loan}/deposit/settle', [LoanDepositController::class, 'settle'])->name('loans.deposit.settle');
        Route::get('reservations', [ReservationController::class, 'staffIndex'])->name('reservations.index');
        Route::post('reservations/{reservation}/approve', [ReservationController::class, 'approve'])->name('reservations.approve');
        Route::post('reservations/{reservation}/ready', [ReservationController::class, 'markReady'])->name('reservations.ready');
        Route::post('reservations/{reservation}/fulfill', [ReservationController::class, 'fulfill'])->name('reservations.fulfill');
        Route::post('reservations/{reservation}/confirm-payment', [PaymentController::class, 'confirmReservation'])->name('reservations.confirm-payment');
        Route::post('reservations/{reservation}/handover', [PaymentController::class, 'handover'])->name('reservations.handover');
        Route::get('payments', [PaymentController::class, 'staffIndex'])->name('payments.index');
        Route::post('payments/{payment}/confirm', [PaymentController::class, 'confirm'])->name('payments.confirm');
        Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
        Route::get('fines', [FineController::class, 'index'])->name('fines.index');
        Route::post('fines', [FineController::class, 'store'])->name('fines.store');
        Route::get('fines/{fine}', [FineController::class, 'show'])->name('fines.show');
        Route::post('fines/{fine}/payments', [FinePaymentController::class, 'store'])->name('fines.payments.store');
        Route::patch('fines/{fine}/waive', [FineController::class, 'waive'])->middleware('role:Admin')->name('fines.waive');
        Route::get('book-suggestions', [BookSuggestionController::class, 'staffIndex'])->name('book-suggestions.index');
        Route::patch('book-suggestions/{suggestion}', [BookSuggestionController::class, 'review'])->name('book-suggestions.review');
    });

Route::middleware(['auth', 'active', 'role:Độc giả'])
    ->group(function () {
        Route::get('/my/membership', [MembershipController::class, 'show'])->name('membership.show');
        Route::post('/my/payment-quotes', [PaymentController::class, 'quote'])->name('payment-quotes.store');
        Route::post('/my/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/my/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('/my/payments/{payment}/transfer', [PaymentController::class, 'reportTransfer'])->name('payments.transfer');
        Route::get('/my/favorites', [FavoriteBookController::class, 'index'])->name('favorites.index');
        Route::post('/my/favorites', [FavoriteBookController::class, 'store'])->name('favorites.store');
        Route::delete('/my/favorites/{book}', [FavoriteBookController::class, 'destroy'])->whereNumber('book')->name('favorites.destroy');
        Route::get('/my/cart', [ReaderCartController::class, 'show'])->name('cart.show');
        Route::post('/my/cart/items', [ReaderCartController::class, 'addItem'])->name('cart.items.store');
        Route::patch('/my/cart/items/{item}', [ReaderCartController::class, 'updateItem'])->name('cart.items.update');
        Route::delete('/my/cart/items/{item}', [ReaderCartController::class, 'removeItem'])->name('cart.items.destroy');
        Route::delete('/my/cart', [ReaderCartController::class, 'clear'])->name('cart.clear');
        Route::post('/my/cart/reservations', [ReaderCartController::class, 'checkout'])->name('cart.reservations.store');
        Route::get('/my/loans', [LoanController::class, 'mine'])->name('loans.mine');
        Route::get('/my/loans/{loan}', [LoanController::class, 'showMine'])->name('loans.show');
        Route::get('/my/reading-books', [ReadingBooksController::class, 'index'])->name('reading-books.index');
        Route::get('/my/reservations', [ReservationController::class, 'mine'])->name('reservations.mine');
        Route::get('/my/reservations/{reservation}', [ReservationController::class, 'show'])->name('reservations.show');
        Route::post('/my/reservations/{reservation}/transfer', [PaymentController::class, 'reportReservationTransfer'])->name('reservations.transfer');
        Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
        Route::delete('/reservations/{reservation}', [ReservationController::class, 'cancel'])->name('reservations.cancel');
        Route::get('/my/fines', [FineController::class, 'mine'])->name('fines.mine');
        Route::post('/books/{book}/reviews', [ReviewController::class, 'store'])->name('books.reviews.store');
        Route::patch('/reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
        Route::delete('/reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
        Route::post('/books/{book}/comments', [BookCommentController::class, 'store'])->name('books.comments.store');
        Route::get('/my/book-suggestions', [BookSuggestionController::class, 'mine'])->name('book-suggestions.mine');
        Route::post('/book-suggestions', [BookSuggestionController::class, 'store'])->name('book-suggestions.store');
    });

require __DIR__.'/reading.php';
