<?php

use App\Models\Reservation;
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
