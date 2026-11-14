<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\LibraryWorkflowNotification;

class LibraryNotifier
{
    public function staff(string $title, string $message, ?User $exclude = null): void
    {
        User::query()
            ->whereIn('role', ['Admin', 'Thủ thư'])
            ->where('is_locked', false)
            ->when($exclude, fn ($query) => $query->whereKeyNot($exclude->id))
            ->get()
            ->each(fn (User $recipient) => $recipient->notify(new LibraryWorkflowNotification(
                $title,
                $message,
                $recipient->role === 'Admin' ? 'admin' : 'librarian-home',
            )));
    }

    public function reader(User $reader, string $title, string $message, string $route): void
    {
        if ($reader->trashed() || $reader->is_locked) {
            return;
        }

        $reader->notify(new LibraryWorkflowNotification($title, $message, $route));
    }
}
