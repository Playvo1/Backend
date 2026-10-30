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

        $notifications = Notification::where('user_id', $user->id)
            ->latest()
            ->paginate(20);

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
