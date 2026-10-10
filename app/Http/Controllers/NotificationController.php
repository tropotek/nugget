<?php

namespace App\Http\Controllers;

use App\Support\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The signed-in user's own notifications. Each is looked up through the
 * user, so nobody reaches another's.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => NotificationFeed::of($request->user())->paginate(25),
        ]);
    }

    /**
     * The top bar's bell and its list alone, which htmx asks for every minute.
     */
    public function menu(): View
    {
        return view('components.layouts.notifications');
    }

    /**
     * Mark a notification read and go where it leads.
     */
    public function show(Request $request, string $notification): RedirectResponse
    {
        $notification = $request->user()->notifications()->findOrFail($notification);
        $notification->markAsRead();

        return redirect()->to(NotificationFeed::present($notification)->url);
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->back(fallback: route('notifications.index'));
    }
}
