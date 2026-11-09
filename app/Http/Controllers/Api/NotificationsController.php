<?php

namespace App\Http\Controllers\Api;

use App\Models\Alert;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\DeviceToken;
use App\Models\FieldAlert;
use App\Models\Lead;
use App\Models\Mailbox;
use App\Models\Notification;
use App\Models\Onboarding;
use App\Models\PtoRequest;
use App\Models\Review;
use App\Models\Suggestion;
use App\Models\TimeClockCorrectionRequest;
use App\Models\TimeClockDayApproval;
use App\Support\Modules\ModuleRegistry;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\PushSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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

    /**
     * Module key => the payload field it opens narrowed by. See [filterFor].
     *
     * @var array<string, string>
     */
    private const MODULE_FILTERS = ['leads' => 'source'];

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
        $events = AutomationEvent::query()
            ->whereIn('id', $rows->pluck('event_id')->filter()->unique())
            ->get(['id', 'event_key', 'payload'])
            ->keyBy('id');

        $notifications = $rows->map(
            fn (Notification $notification) => $this->present($notification, $events),
        );

        return response()->json([
            'data' => $notifications,
            'meta' => [
                'unread' => (clone $base)->whereNull('read_at')->count(),
                'alerts' => $this->openAlerts($base),
            ],
        ]);
    }

    /**
     * Every alert this person has not dismissed, oldest first — whatever the
     * page above contains.
     *
     * The bell asks for its newest 20, so an alert older than that would never
     * reach the client to pop up: somebody away over a busy weekend would
     * simply never see it. Alerts (Communication ▸ Alerts, Jose 2026-10-07)
     * are the one kind that must be acknowledged, so they ride this same poll
     * in full rather than costing a second request every sixty seconds.
     * Oldest first because they queue on screen in the order they were sent.
     *
     * @return list<array{id: string, title: string, body: ?string, sender: ?string, created_at: ?string}>
     */
    private function openAlerts(Builder $base): array
    {
        $rows = (clone $base)
            ->where('source', 'alert')
            ->whereNull('read_at')
            ->oldest('created_at')
            ->limit(50)
            ->get(['id', 'title', 'body', 'subject_type', 'subject_id', 'created_at']);

        $senders = Alert::query()
            ->whereIn('id', $rows->where('subject_type', Alert::class)->pluck('subject_id'))
            ->with('sender:id,name')
            ->get(['id', 'sender_id'])
            ->keyBy('id');

        return $rows->map(fn (Notification $row) => [
            'id' => $row->id,
            'title' => $row->title,
            'body' => $row->body,
            'sender' => $senders->get($row->subject_id)?->sender?->name,
            'created_at' => $row->created_at?->toIso8601String(),
        ])->values()->all();
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
     * @param  Collection<string, AutomationEvent>  $events  keyed by id
     */
    /** @var array<string, string> */
    private array $headers = [];

    // ponytail: one subject lookup per distinct record on a page of 20; batch by type if pages grow.
    private function headerFor(Notification $notification): string
    {
        $type = $notification->subject_type;
        $id = $notification->subject_id;

        return $this->headers[$type.'|'.$id] ??= app(NotificationDispatcher::class)->headerFor(
            $type !== null && $id !== null && class_exists($type) ? $type::query()->find($id) : null,
        );
    }

    private function present(Notification $notification, $events): array
    {
        return [
            'id' => $notification->id,
            'source' => $notification->source,
            'title' => $notification->title,
            'body' => $notification->body,
            'urgent' => (bool) $notification->urgent,
            'subject' => [
                'type' => $notification->subject_type ? class_basename($notification->subject_type) : null,
                'id' => $notification->subject_id,
            ],
            'meta' => $this->destination($notification, $events),
            // The inbox's "Assigned to me" tab (monday port, 2026-11-09): the
            // event that sent it handed this person something. There is no
            // @mention anywhere in EzBizi yet, so no "mentioned" kind either.
            'kind' => $this->kindOf($notification, $events),
            // Jose, 2026-10-22: the panel and the desktop toast lead with the
            // same "EzBizi - Company - Branch" as the push and the mail.
            'header' => $this->headerFor($notification),
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }

    private function kindOf(Notification $notification, $events): ?string
    {
        $key = $notification->event_id === null ? null : $events->get($notification->event_id)?->event_key;

        return $key !== null && preg_match('/\.(assigned|assign|assign_to_user|assignment_added)$/', $key) ? 'assigned' : null;
    }

    /**
     * The module a notification WITHOUT an event belongs to, by its subject.
     *
     * Bar, 2026-10-12 (via Jose and the deploy desk): tapping a notification
     * must open the exact thing it is about. Measured on production: system
     * notifications — "Your Work Hours correction was declined", "Your
     * suggestion was approved", an SOS, onboarding paperwork — carry a subject
     * but no event, so they derived no module at all and a tap went nowhere.
     *
     * Every key here is a real module (asserted by a test) except
     * `suggestions`, which is the shell's own pinned destination; the client
     * shell routes that one key to its pinned rail.
     *
     * @var array<class-string, string>
     */
    private const SUBJECT_MODULES = [
        Suggestion::class => 'suggestions',
        Lead::class => 'leads',
        TimeClockCorrectionRequest::class => 'work_hours',
        TimeClockDayApproval::class => 'work_hours',
        PtoRequest::class => 'work_hours',
        Automation::class => self::AUTOMATIONS_MODULE,
        FieldAlert::class => 'field',
        Onboarding::class => 'hr',
        Mailbox::class => 'email',
        Review::class => 'reviews',
    ];

    /**
     * Where a notification points: a module, what to narrow it to, and the
     * exact record to open.
     *
     * @param  Collection<string, AutomationEvent>  $events
     * @return array{module: ?string, filter: ?string, record: ?array{type: string, id: string, mailbox?: ?string}}
     */
    private function destination(Notification $notification, $events): array
    {
        $event = $notification->event_id === null ? null : $events->get($notification->event_id);
        $destination = $this->destinationModuleAndFilter($notification, $event);
        $destination['record'] = $this->recordFor($notification, $event);

        return $destination;
    }

    /**
     * The exact thing a tap should open, as {type, id} — or null.
     *
     * Usually the notification's subject. Two exceptions, both measured: a new
     * email's subject is the MAILBOX, but Bar's example is that it should open
     * "that email", so the message comes from the event (email.received
     * carries message_id and the mailbox address); and a missed call has no
     * subject at all, so its call comes from the event's call_id.
     */
    private function recordFor(Notification $notification, ?AutomationEvent $event): ?array
    {
        if ($event?->event_key === 'email.received' && is_string($id = data_get($event->payload, 'message_id'))) {
            return ['type' => 'email_message', 'id' => $id, 'mailbox' => data_get($event->payload, 'address')];
        }

        if ($event?->event_key === 'reports.call.missed' && is_string($id = data_get($event->payload, 'call_id'))) {
            return ['type' => 'call', 'id' => $id];
        }

        if ($notification->subject_type === null || $notification->subject_id === null) {
            return null;
        }

        // Daily approval (design 136): the reminder opens Work Hours › Daily
        // approval on the day it is about, and the approval row's id alone
        // cannot tell the client which day that is.
        if ($notification->subject_type === TimeClockDayApproval::class) {
            return [
                'type' => 'time_clock_day_approval',
                'id' => (string) $notification->subject_id,
                'date' => TimeClockDayApproval::query()->whereKey($notification->subject_id)->first()?->date?->toDateString(),
            ];
        }

        return [
            'type' => Str::snake(class_basename($notification->subject_type)),
            'id' => (string) $notification->subject_id,
        ];
    }

    /** @return array{module: ?string, filter: ?string} */
    private function destinationModuleAndFilter(Notification $notification, ?AutomationEvent $event): array
    {
        $module = $event !== null
            ? $this->modules->moduleKeyOfTrigger($event->event_key)
            /*
             * A notification that NAMES an automation is about that automation —
             * "Automation switched off: Missed Call" is raised by the engine
             * rather than by a trigger, so it carries no event to derive from.
             *
             * The key is written here rather than derived, deliberately after
             * the `emits` mistake: this is not a guess about ownership, it is
             * the meaning of the column. A test asserts it names a real module.
             */
            : ($notification->automation_id === null
                ? (self::SUBJECT_MODULES[$notification->subject_type] ?? null)
                : self::AUTOMATIONS_MODULE);

        return ['module' => $module, 'filter' => $this->filterFor($module, $event)];
    }

    /**
     * What the destination should open narrowed to, when it understands one.
     *
     * Jose, 2026-09-16: a lead notification should reach "the leads tab and
     * specifically the chat sub tab". The Leads screen narrows by the lead's
     * `source`, and the value is already in the event payload — `lead.received`
     * emits `after.source` — so nothing here reads the leads table and the
     * client fetches nothing extra.
     *
     * ── Why a map rather than "pass through any after.source" ────────
     *
     * Because `source` is not a reserved word. Another module's payload may
     * carry a field of that name meaning something entirely different, and
     * handing it to a screen as a filter would narrow a list by a value that
     * was never about narrowing. The Leads screen ignores a source it does not
     * recognise — measured and tested by whoever added `initialSource` — so the
     * failure would be quiet, which is exactly the kind this module has spent
     * the day removing.
     *
     * One entry, because one module has asked. A second adds a line here and
     * declares which payload field it narrows by.
     */
    private function filterFor(?string $module, ?AutomationEvent $event): ?string
    {
        $field = self::MODULE_FILTERS[$module] ?? null;

        if ($field === null || $event === null) {
            return null;
        }

        $value = data_get($event->payload, "after.{$field}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
