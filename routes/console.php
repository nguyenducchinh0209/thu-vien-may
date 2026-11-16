<?php

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reservations:expire', function () {
    $expired = Reservation::query()
        ->where('status', 'ready')
        ->where('expires_at', '<=', now())
        ->update(['status' => 'expired']);

    $this->info("Expired {$expired} reservation(s).");
})->purpose('Expire reservations whose pickup window has ended');

Schedule::command('reservations:expire')->everyMinute();

Artisan::command('library:admin', function (): int {
    $name = trim((string) $this->ask('Họ tên'));
    $email = mb_strtolower(trim((string) $this->ask('Email')));
    $password = (string) $this->secret('Mật khẩu (ít nhất 12 ký tự)');
    $validation = Validator::make([
        'name' => $name, 'email' => $email, 'password' => $password,
        'password_confirmation' => $this->secret('Nhập lại mật khẩu'),
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
    ]);
    if ($validation->fails()) {
        $this->error($validation->errors()->first());

        return 1;
    }

    User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'Admin']);
    $this->info('Đã tạo tài khoản Admin.');

    return 0;
})->purpose('Create an administrator with a password entered at the terminal');
