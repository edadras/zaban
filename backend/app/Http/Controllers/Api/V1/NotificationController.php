<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;

/**
 * The bell.
 *
 * Reads Laravel's own notifications table, so anything in the product that
 * calls `$user->notify(...)` appears here without a second mechanism. The first
 * thing to use it is a class about to start.
 */
class NotificationController extends ApiController
{
    public function index(Request $request)
    {
        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $notifications = $query->limit(min(100, $request->integer('limit', 30)))->get();

        return $this->ok(
            $notifications->map(fn ($n) => [
                'id' => $n->id,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            ['unread' => $request->user()->unreadNotifications()->count()],
        );
    }

    public function read(Request $request, string $id)
    {
        $request->user()->notifications()->whereKey($id)->update(['read_at' => now()]);

        return $this->ok(['read' => true]);
    }

    public function readAll(Request $request)
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->ok(['read' => $count]);
    }
}
