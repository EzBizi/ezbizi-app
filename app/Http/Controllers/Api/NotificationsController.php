<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Support\Tasks\TaskNotificationPresenter;
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
 *
 * No audit either, on the reasoning PreferencesController sets out: read state
 * is a fact about one person's own inbox and changes nothing anybody else can
 * observe. markAllRead in particular fires every time the panel is opened, so
 * auditing it would add the loudest rows in the table to the log least likely to
 * ever be read — which is the failure §4.2 worries about when it talks about
 * development noise drowning real business activity.
 */
class NotificationsController extends Controller
{
    public function __construct(private readonly TaskNotificationPresenter $presenter) {}

    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $base = $this->presenter->constrain(Notification::query()
            ->where('user_id', $request->user()->getKey()), $request->user());
        $notifications = (clone $base)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get()
            ->map(fn (Notification $notification) => $this->presenter->present($notification));

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'unread' => (clone $base)->whereNull('read_at')->count(),
            ],
        ]);
    }

    /** POST /api/v1/notifications/{notification}/read */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        // Not found rather than forbidden — 403 would confirm the id belongs to
        // a real notification of somebody else's.
        abort_unless($notification->user_id === $request->user()->getKey()
            && $this->presenter->visible($notification, $request->user()), 404);

        $notification->markRead();

        return response()->json(['data' => ['id' => $notification->id, 'read_at' => $notification->read_at]]);
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->presenter->constrain(Notification::query()
            ->where('user_id', $request->user()->getKey()), $request->user())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }
}
