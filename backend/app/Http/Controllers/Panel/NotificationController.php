<?php

namespace App\Http\Controllers\Panel;

/** The bell, for a coach who was summoned to cover somebody's class. */
class NotificationController extends PanelController
{
    public function index()
    {
        return view('panel.notifications', [
            'notifications' => $this->me()->notifications()->latest()->limit(100)->get(),
        ]);
    }

    public function readAll()
    {
        $this->me()->unreadNotifications->markAsRead();

        return back()->with('status', 'همه خوانده شد.');
    }
}
