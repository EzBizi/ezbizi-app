<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
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
    /** Verified to name a real module by NotificationsTest. */
    private const AUTOMATIONS_MODULE = 'automations';

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** GET /api/v1/notifications */
    public function index(Request $request): JsonResponse
    {
        $base = Notification::query()->where('user_id', $request->user()->getKey());
        $rows = (clone $base)
            ->when($request->boolean('unread_only'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        /*
         * The trigger key of every event behind this page, in ONE query.
         *
         * Asked per notification it would be an N+1 against the fastest-growing
         * table in the system, on the request the bell makes every sixty
         * seconds on every open client.
         */
        $triggerKeys = AutomationEvent::query()
            ->whereIn('id', $rows->pluck('event_id')->filter()->unique())
            ->pluck('event_key', 'id');

        $notifications = $rows->map(
            fn (Notification $notification) => $this->present($notification, $triggerKeys),
        );

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
     * ── Derived from the TRIGGER, after `emits` got it wrong ─────────
     *
     * The first version of this read `ModuleDefinition::emits` — "every model
     * this module passes to events->emit()" — and asked which module emits the
     * subject's class. It shipped, and clicking "New Live Chat Lead" opened the
     * DASHBOARD.
     *
     * `emits` is not an ownership map and never claimed to be. TWO modules emit
     * `Lead` (`web_management` and `leads`), so the answer was decided by
     * registry iteration order and came back `web_management`. Worse, it was
     * wrong even where it looked unambiguous: `Job` is emitted only by `leads`,
     * because the `leads` module raises job events — so a job notification
     * would have opened Leads rather than Jobs, with nothing to tell anybody it
     * had. I derived from a source that answers a neighbouring question.
     *
     * The trigger is the source that answers THIS one. `lead.received` is
     * declared by `leads` and `job.created` by `jobs`, because a module
     * declares the events it raises — and a notification raised by `notify`
     * carries the event that caused it. One module per trigger, by
     * construction, so there is no order to depend on.
     *
     * ── Why there is no fall-back to the class ───────────────────────
     *
     * Because the class answer was wrong in both directions above, and a
     * fallback that is wrong is worse than none: it takes somebody somewhere
     * confidently. A notification with no event is left unclickable unless it
     * names an automation, which is the one case where the destination is a
     * fact about the row rather than a guess — see below.
     *
     * A MODULE KEY, not a path: the shell selects a module by id and no module
     * accepts a record to open within it yet. `subject.id` is in the payload
     * for whoever builds that.
     *
     * @param  \Illuminate\Support\Collection<string, string>  $triggerKeys  event id => event_key
     */
    private function present(Notification $notification, $triggerKeys): array
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
            'meta' => ['module' => $this->moduleFor($notification, $triggerKeys)],
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    /**
     * Where a notification points, or null when it points nowhere.
     *
     * @param  \Illuminate\Support\Collection<string, string>  $triggerKeys
     */
    private function moduleFor(Notification $notification, $triggerKeys): ?string
    {
        $triggerKey = $notification->event_id === null
            ? null
            : $triggerKeys->get($notification->event_id);

        if ($triggerKey !== null) {
            return $this->modules->moduleKeyOfTrigger($triggerKey);
        }

        /*
         * A notification that NAMES an automation is about that automation —
         * "Automation switched off: Missed Call" is raised by the engine rather
         * than by a trigger, so it carries no event to derive from.
         *
         * The key is written here rather than derived, and that is deliberate
         * after the `emits` mistake: this is not a guess about ownership, it is
         * the meaning of the column. A test asserts the key names a module that
         * exists, so a rename cannot leave this pointing at nothing.
         */
        return $notification->automation_id === null ? null : self::AUTOMATIONS_MODULE;
    }
}
