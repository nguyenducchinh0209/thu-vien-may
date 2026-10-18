<?php

use App\Http\Controllers\LibraryPageController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', LibraryPageController::class)->name('library');
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('auth.register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:8,1')->name('auth.login');
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth')->name('auth.logout');
Route::get('/auth/session', fn () => response()->json([
    'user' => request()->user()?->only(['id', 'name', 'email', 'role']),
]))->middleware('auth')->name('auth.session');
Route::get('/staff', fn () => response()->json(['message' => 'Khu vực nhân viên thư viện.']))
    ->middleware(['auth', 'role:Admin,Thủ thư'])->name('staff.home');
