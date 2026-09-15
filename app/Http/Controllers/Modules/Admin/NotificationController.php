<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 200);
        $limit = min(max(1, $limit), 1000);

        $adminIds = collect([$user->id]);
        if ($user->type === User::TYPE_SUPERADMIN || $user->type === User::TYPE_ADMIN) {
            $employerIds = User::where('parent_id', $user->id)->pluck('id');
            $adminIds = $adminIds->merge($employerIds)->unique();
        }

        $query = Notification::whereIn('user_id', $adminIds)->latest();
        if ($request->filled('unread') && (bool) $request->input('unread')) {
            $query->unread();
        }
        if ($request->filled('category')) {
            $q = strtolower($request->input('category'));
            if ($q !== 'all') {
                $query->where('category', $q);
            }
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $notifications = $query->limit($limit)->get();
        $unreadCount = Notification::whereIn('user_id', $adminIds)->unread()->count();

        return $this->sendResponse([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ], 'Notifications retrieved');
    }

    public function markAsRead(Request $request, int $id)
    {
        $user = $request->user();
        $adminIds = collect([$user->id]);
        if ($user->type === User::TYPE_SUPERADMIN || $user->type === User::TYPE_ADMIN) {
            $employerIds = User::where('parent_id', $user->id)->pluck('id');
            $adminIds = $adminIds->merge($employerIds)->unique();
        }

        $notification = Notification::whereIn('user_id', $adminIds)->findOrFail($id);
        $notification->markAsRead();

        $unreadCount = Notification::whereIn('user_id', $adminIds)->unread()->count();
        return $this->sendResponse([
            'notification' => $notification->fresh(),
            'unread_count' => $unreadCount,
        ], 'Marked as read');
    }

    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        $adminIds = collect([$user->id]);
        if ($user->type === User::TYPE_SUPERADMIN || $user->type === User::TYPE_ADMIN) {
            $employerIds = User::where('parent_id', $user->id)->pluck('id');
            $adminIds = $adminIds->merge($employerIds)->unique();
        }

        Notification::whereIn('user_id', $adminIds)->unread()->update(['read_at' => now()]);
        return $this->sendResponse([
            'unread_count' => 0,
        ], 'All notifications marked as read');
    }
}
