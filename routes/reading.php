<?php

use App\Http\Controllers\EpubReadingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'role:Admin,Thủ thư,Độc giả'])->group(function (): void {
    Route::get('/books/{book}/read', [EpubReadingController::class, 'show'])->name('books.read');
});

Route::middleware(['auth', 'active', 'role:Admin,Thủ thư'])
    ->prefix('staff')
    ->name('staff.')
    ->group(function (): void {
        Route::get('/books/{book}/epub/upload', [EpubReadingController::class, 'uploadForm'])->name('books.epub.upload');
        Route::post('/books/{book}/epub', [EpubReadingController::class, 'upload'])->name('books.epub.store');
    });
