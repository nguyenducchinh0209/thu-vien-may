<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => $user->notifications()
                ->latest()
                ->limit(40)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at?->toISOString(),
                ]),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json(['message' => 'Đã đánh dấu thông báo đã đọc.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Đã đánh dấu tất cả thông báo đã đọc.']);
    }
}
