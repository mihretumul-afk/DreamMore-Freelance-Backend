<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends BaseApiController
{
    /**
     * List notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::where('user_id', $user->id)
            ->orderByDesc('created_at');

        if ($request->filled('read')) {
            if ($request->input('read') === 'true') {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        $notifications = $query->paginate(20);

        return $this->sendResponse(
            $notifications->items(),
            'Notifications retrieved successfully.',
            200,
            [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ]
        );
    }

    /**
     * Mark a specific notification as read.
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id) {
            return $this->sendForbidden('You can only mark your own notifications as read.');
        }

        if (is_null($notification->read_at)) {
            $notification->update(['read_at' => now()]);
        }

        return $this->sendResponse($notification->fresh(), 'Notification marked as read.');
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->sendResponse(null, 'All notifications marked as read.');
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return $this->sendResponse(['unread_count' => $count], 'Unread count retrieved.');
    }
}
