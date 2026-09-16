<?php

namespace App\Http\Controllers\Modules\Partner;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $notifs = Notification::forUser($u)->orderByDesc('created_at')->limit(100)->get();
        $unread = $notifs->whereNull('read_at')->count();

        return $this->sendResponse([
            'notifications' => $notifs->map(function ($n) {
                return $n->only([
                    'id', 'category', 'type', 'title', 'body', 'icon',
                    'deep_link', 'metadata', 'read_at', 'created_at',
                ]);
            }),
            'unread_count' => $unread,
        ], 'Partner notifications retrieved');
    }

    public function markAsRead(Request $request, $id)
    {
        $u = $request->user();
        $n = Notification::forUser($u)->findOrFail($id);
        $n->markAsRead();
        return $this->sendResponse(null, 'Marked as read');
    }

    public function markAllAsRead(Request $request)
    {
        $u = $request->user();
        Notification::forUser($u)->unread()->update(['read_at' => now()]);
        return $this->sendResponse(null, 'All notifications marked read');
    }
}
