<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LibraryWorkflowNotification;

class LibraryNotifier
{
    public function librarians(string $title, string $message, string $route): void
    {
        User::query()
            ->where('role', 'Thủ thư')
            ->where('is_locked', false)
            ->get()
            ->each(fn (User $recipient) => $recipient->notify(new LibraryWorkflowNotification(
                $title,
                $message,
                $route,
                'Thủ thư',
            )));
    }

    public function reader(User $reader, string $title, string $message, string $route): void
    {
        if ($reader->trashed() || $reader->is_locked) {
            return;
        }

        $reader->notify(new LibraryWorkflowNotification($title, $message, $route, 'Độc giả'));
    }
}
