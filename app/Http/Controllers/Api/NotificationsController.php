<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Your own notifications (plan §12).
 *
 * No policy, and that is deliberate: every query here is bound to
 * $request->user()->getKey(), so there is nothing to authorize beyond being
 * signed in. A permission would suggest one user might read another's, which is
 * never true — and a policy that always returns true is a place for a bug to
 * hide.
 */
class NotificationsController extends Controller
{
    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get()
            ->map(fn (Notification $n) => [
                'id'      => $n->id,
                'source'  => $n->source,
                'title'   => $n->title,
                'body'    => $n->body,
                'subject' => [
                    // Class basename, not the FQCN. The client routes on a word
                    // like "Job"; handing it App\Models\Job leaks the namespace
                    // layout into a published API for no benefit (§11.4).
                    'type' => $n->subject_type ? class_basename($n->subject_type) : null,
                    'id'   => $n->subject_id,
                ],
                'read_at'    => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'unread' => Notification::query()
                    ->where('user_id', $request->user()->getKey())
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    /** POST /api/v1/notifications/{notification}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Not found rather than forbidden — 403 would confirm the id belongs to
        // a real notification of somebody else's.
        abort_unless($notification->user_id === $request->user()->getKey(), 404);

        $notification->markRead();

        return response()->json(['data' => ['id' => $notification->id, 'read_at' => $notification->read_at]]);
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = Notification::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }
}
