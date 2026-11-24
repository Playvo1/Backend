<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['sometimes', 'in:unread,read'],
        ]);

        $notifications = Notification::where('user_id', $user->id)
            ->when(isset($validated['status']), fn ($q) => $q->where('is_read', $validated['status'] === 'read'))
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // The user's total unread count, whatever the filter.

        $unreadCount = Notification::where('user_id', $user->id)
            ->where('is_read', false)
            ->count();

        return response()->json([
            'success' => true,
            // The list stays where the mobile app reads it (data.data); the count sits beside it.
            'data' => array_merge($notifications->toArray(), [
                'unread_count' => $unreadCount,
            ]),
            'message' => 'Notifications retrieved successfully.',
            'errors' => null,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $updated = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'updated' => $updated,
                'unread_count' => 0,
            ],
            'message' => 'All notifications marked as read.',
            'errors' => null,
        ]);
    }

    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where(
            'user_id',
            $request->user()->id
        )->findOrFail($id);

        $notification->update([
            'is_read' => true,
        ]);

        return response()->json([
            'success' => true,
            'data' => $notification->fresh(),
            'message' => 'Notification marked as read.',
            'errors' => null,
        ]);
    }
}
