<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\LibraryWorkflowNotification;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'unread_count' => $this->inbox($request->user())->whereNull('read_at')->count(),
            'data' => $this->inbox($request->user())
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
        $record = $this->inbox($request->user())->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json(['message' => 'Đã đánh dấu thông báo đã đọc.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->inbox($request->user())->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'Đã đánh dấu tất cả thông báo đã đọc.']);
    }

    private function inbox(User $user): MorphMany
    {
        // Older workflow notices encoded the recipient's role in the destination.
        $legacyRoutes = match ($user->role) {
            'Thủ thư' => ['librarian-home'],
            'Độc giả' => ['reservations', 'fines', 'profile', 'shelf'],
            default => [],
        };

        return $user->notifications()->where(function ($query) use ($user, $legacyRoutes): void {
            $query->where('type', '!=', LibraryWorkflowNotification::class)
                ->orWhere('data->audience', $user->role)
                ->orWhere(fn ($legacy) => $legacy->whereNull('data->audience')->whereIn('data->route', $legacyRoutes));
        });
    }
}
