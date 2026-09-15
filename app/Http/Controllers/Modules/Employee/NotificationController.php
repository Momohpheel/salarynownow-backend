<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 200);
        $limit = min(max(1, $limit), 1000);

        $query = Notification::forUser($user)->latest();
        if ($request->filled('unread') && (bool) $request->input('unread')) {
            $query->unread();
        }
        if ($request->filled('category')) {
            $q = strtolower($request->input('category'));
            if ($q !== 'all') {
                $query->where('category', $q);
            }
        }

        $notifications = $query->limit($limit)->get();
        $unreadCount = Notification::forUser($user)->unread()->count();

        return $this->sendResponse([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ], 'Notifications retrieved');
    }

    public function markAsRead(Request $request, int $id)
    {
        $notification = Notification::forUser($request->user())->findOrFail($id);
        $notification->markAsRead();

        $unreadCount = Notification::forUser($request->user())->unread()->count();
        return $this->sendResponse([
            'notification' => $notification->fresh(),
            'unread_count' => $unreadCount,
        ], 'Marked as read');
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        Notification::forUser($user)->unread()->update(['read_at' => now()]);
        return $this->sendResponse([
            'unread_count' => 0,
        ], 'All notifications marked as read');
    }
}
