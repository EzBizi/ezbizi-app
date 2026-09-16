<?php

namespace App\Http\Controllers\Api;

use App\Models\DeviceToken;
use App\Models\Notification;
use App\Support\Modules\ModuleRegistry;
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
    public function __construct(private readonly ModuleRegistry $modules) {}

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
            /*
             * Derived from the senders that exist rather than listed here, so
             * the rule and the capability cannot drift apart. Both platforms
             * have one today.
             *
             * This comment said "iOS is absent until there is an APNs sender"
             * for as long as that was true, and then for a while after it was
             * not — the Android module spotted it sitting one line above the
             * rule that had started accepting iOS. That is the more dangerous
             * half of the same mistake they had made in a docblock: a docblock
             * nobody executes is read by somebody curious, and THIS is read by
             * somebody deciding whether iOS is wired, next to the code that
             * proves it is.
             *
             * What the endpoint still cannot check: an iOS row is only
             * deliverable if the token came from `registerForRemoteNotifications`
             * rather than from PushKit. Both are hex strings of plausible
             * length and this rule cannot tell them apart — the guarantee lives
             * in the Swift half and in the client returning early off Android.
             */
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

    /**
     * One notification, with somewhere to go.
     *
     * ── `meta.module`, replacing a `meta.route` that was always null ──
     *
     * The old key promised a path and delivered nothing on every notification
     * ever sent, and nothing in the client read it. Clicking a notification
     * marked it read and left you exactly where you were — which for "New Live
     * Chat Lead" means being told about a lead and given no way to open it.
     *
     * A MODULE KEY rather than a path, because that is the unit the client can
     * actually act on: the shell selects a module by id, and no module today
     * accepts a record to open within it. Deep-linking to the individual lead
     * is a per-module change owned by each of those modules, not something this
     * controller can invent — so this goes as far as the shell can go and no
     * further, rather than shipping a path nobody honours. The record's id is
     * already in `subject.id` for whoever builds that next.
     *
     * Null is an ordinary answer, not a gap: a notification about an
     * Organization, or one with no subject, has nowhere more specific to go
     * than where you already are.
     */
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
            'meta' => [
                'module' => $notification->subject_type === null
                    ? null
                    : $this->modules->moduleKeyOfModel($notification->subject_type),
            ],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
