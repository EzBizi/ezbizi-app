<?php

namespace App\Http\Controllers\Api;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Support\Notifications\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $base = Notification::query()->where('user_id', $request->user()->getKey());
        $notifications = (clone $base)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get()
            ->map(fn (Notification $notification) => $this->present($notification));

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
        abort_unless($notification->user_id === $request->user()->getKey(), 404);

        $notification->markRead();

        return response()->json(['data' => ['id' => $notification->id, 'read_at' => $notification->read_at]]);
    }

    /** POST /api/v1/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $marked = Notification::query()->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $marked]]);
    }

    /**
     * POST /api/v1/notifications/devices — this handset can be pushed to.
     *
     * ── Idempotent on the TOKEN, because the client re-sends it ──────
     *
     * The Android client presents its token on every launch and again whenever
     * FCM rotates it, so the same value arrives many times and must not
     * accumulate rows. The token is the identity here, not the user: it names
     * an INSTALL, and when somebody else signs in on the same handset the row
     * moves to them rather than a second row appearing beside it.
     *
     * ── Why the lookup escapes the tenant scope ──────────────────────
     *
     * `token` is globally unique, and a device can legitimately cross
     * organizations — somebody signs into a different business on the same
     * phone. A tenant-scoped lookup would not find the existing row, the insert
     * would hit the unique index, and registration would fail with a 500 on a
     * device that simply changed hands. Finding it regardless and reassigning it
     * is both the correct outcome and the safe one: the row ends up owned by
     * whoever is actually signed in, which is exactly who may be told things.
     *
     * ── No audit row ─────────────────────────────────────────────────
     *
     * Same reasoning as the read state above. A handset announcing itself is
     * not business activity, it fires on every launch, and auditing it would
     * add the loudest rows in the system to the log least likely to be read.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            // Derived from the senders that exist rather than listed here, so
            // the rule and the capability cannot drift apart. iOS is absent
            // until there is an APNs sender — see PushSender::platforms().
            'platform' => ['required', 'string', Rule::in(app(PushSender::class)->platforms())],
        ]);

        $user = $request->user();

        $device = DeviceToken::withoutGlobalScopes()->where('token', $data['token'])->first()
            ?? new DeviceToken(['token' => $data['token'], 'created_at' => now()]);

        $device->forceFill([
            'organization_id' => $user->organization_id,
            'user_id' => $user->getKey(),
            'platform' => $data['platform'],
            'last_registered_at' => now(),
        ])->save();

        return response()->json(['data' => ['registered' => true]]);
    }

    /** No module currently gives a notification a route of its own. */
    private function present(Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'source' => $notification->source,
            'title' => $notification->title,
            'body' => $notification->body,
            'subject' => [
                'type' => $notification->subject_type ? class_basename($notification->subject_type) : null,
                'id' => $notification->subject_id,
            ],
            'meta' => ['route' => null],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
