<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LibraryWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $route,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'route' => $this->route,
        ];
    }
}
