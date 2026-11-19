<?php

namespace App\Support\Notifications;

use App\Http\Controllers\Api\NotificationsController;
use App\Models\Assignment;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\DeviceToken;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Notifications\NotificationNotice;
use App\Support\Access\ScopeReach;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Deciding who to tell, and telling them (plan §12).
 *
 * ── Why the audience is resolved from REACH ──────────────────────
 *
 * An automation says "notify somebody about this job". The only defensible answer
 * to "who" is the people who could have seen the job anyway: sending a
 * notification about a record whose contents the recipient is not allowed to
 * read would be a leak dressed up as a feature, and the title alone often gives
 * the record away.
 *
 * So reach decides the audience, exactly as it decides the job list (§4). A
 * franchise-level user hears about their franchise; a company-level user hears
 * about everything beneath them, Sub Companies included.
 */
class NotificationDispatcher
{
    /**
     * A hard ceiling per dispatch.
     *
     * An organization-wide rule firing on every job in a busy franchise would
     * otherwise write a row per user per event, and the first anyone notices is
     * the bell showing four thousand.
     */
    private const MAX_RECIPIENTS = 200;

    /**
     * The email ceiling, deliberately far below the row ceiling.
     *
     * Fifty rather than two hundred because the two media fail differently: a
     * bell nobody wanted is dismissed, and a mailshot nobody wanted is a
     * complaint against a sending reputation shared by every organization on
     * the platform. Fifty is high enough that no ordinary audience reaches it
     * and low enough to stop a rule firing per job from mailing a franchise.
     *
     * Exceeding it sends nothing rather than a subset — see alsoByEmail.
     */
    private const MAX_EMAIL_RECIPIENTS = 50;

    /**
     * Which setting switches each `source` off, and the reason this is a map
     * rather than a string built from the source name.
     *
     * A built key — "notifications.{$source}_enabled" — would ask the registry
     * for a definition that may not exist the moment any module invents a new
     * source, and `getOrFail` would turn "we have not written a toggle for this
     * yet" into an exception thrown from inside a queue worker, losing the
     * notification. A source absent from this map is ALLOWED (see [allows]),
     * which is the same fail-open direction the version gate takes for a
     * platform it does not recognise.
     *
     * `system` is absent deliberately and permanently: it is the source that
     * carries "your trial ends tomorrow".
     */
    private const SOURCE_SETTINGS = [
        'automation' => 'notifications.automation_enabled',
        'hr' => 'notifications.hr_enabled',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly ScopeReach $reach,
        private readonly PushSender $push,
    ) {}

    /**
     * @param  Model|null  $subject  what it is about; also decides the audience
     * @return int how many people were told
     */
    public function dispatch(
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        ?User $onlyTo = null,
        string $source = 'system',
        ?string $automationId = null,
        ?string $eventId = null,
        bool $inAppOnly = false,
        array $roleIds = [],
        array $userIds = [],
        bool $urgent = false,
        /*
         * What kind of event this is, e.g. `task.assigned` (KINDS). Lets each
         * person switch a kind off, and groups a burst of the same kind on the
         * same record into one row (Jose, 2026-11-17: task board coverage).
         */
        ?string $kind = null,
    ): int {
        if (! $this->allows($source, $subject, $inAppOnly)) {
            return 0;
        }

        $recipients = $this->narrowToChosen(
            $onlyTo !== null ? collect([$onlyTo]) : $this->audienceFor($subject),
            $roleIds,
            $userIds,
        );

        $recipients = $this->withoutFlooded($recipients, $automationId);
        $recipients = $this->onlyThoseFollowing($recipients, $subject, $source, $eventId);
        $recipients = $this->withoutMutedOrRepeatedKind($recipients, $kind, $subject, $title);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $told = $recipients->take(self::MAX_RECIPIENTS);
        $scope = $this->settingsScope($subject);

        $rows = $told->map(fn (User $user) => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->tenant->idOrFail(),
            'user_id' => $user->getKey(),
            'source' => $source,
            'kind' => $kind === null ? null : mb_substr($kind, 0, 40),
            'title' => $title,
            'body' => $body,
            'urgent' => $urgent,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'automation_id' => $automationId,
            'event_id' => $eventId,
            'created_at' => now(),
        ])->all();

        /*
         * THE BELL IS A CHANNEL NOW, NOT THE WHOLE SYSTEM.
         *
         * `notifications.in_app_enabled` decides the ROW and nothing else. It
         * spent August calling itself "the master switch" in its own help — I
         * wrote that sentence, and it was true and complete about a system with
         * one channel. It never contemplated a second one, and reading a
         * promise about email out of it is reading intent into a sentence
         * written before the question existed.
         *
         * The key is NAMED `in_app_enabled`. Somebody who unticks "Show in-app
         * notifications" and silently stops receiving MAIL has been lied to by
         * the name, and silently losing mail is the worse of the two surprises.
         *
         * What is genuinely cross-channel is the SOURCE map, which allows()
         * asks — a company that silences automations is silenced on every
         * channel, now and for any channel added later. Nothing is lost by the
         * split, including the case my old sentence was protecting: `system`
         * has no source toggle deliberately, and is still switchable off on
         * each channel independently by that channel's own key.
         */
        $rowsWritten = 0;

        if ($this->channelOn('notifications.in_app_enabled', $scope)) {
            // One insert. A loop of saves would be one query per recipient, and
            // this runs inside a queue worker handling every event on the
            // platform.
            Notification::insert($rows);
            $rowsWritten = count($rows);
        }

        // Each person's own row id, for the push: a tap on a phone opens the
        // app with it and routes exactly as tapping the bell row would (Bar,
        // 2026-10-12: a notification must open "the exact thing"). Empty when
        // the bell is off — no row exists for a tap to point at.
        $rowIds = $rowsWritten > 0 ? collect($rows)->pluck('id', 'user_id')->all() : [];

        /*
         * A RULE MAY DECLINE THE EMAIL. IT MAY NOT DEMAND ONE.
         *
         * `$inAppOnly` is the automation builder's per-rule switch, and it is
         * phrased as a NEGATIVE deliberately: an untouched switch sends a real
         * `false`, so a positively-phrased key would have silenced the email on
         * every rule that already exists the day it shipped.
         *
         * It only ever narrows. allows() has already applied the organization's
         * settings above, so a rule cannot turn on a channel the company
         * switched off — which keeps the answer to "why did I get this email"
         * one switch rather than two places that have to be read together.
         */
        /*
         * WHO HAS NOT MUTED THEIR OWN ALERTS — the email and the push below
         * go only to them; the bell rows above went to everybody.
         *
         * `notifications.alerts` is each person's switch in the bell panel
         * (Jose, 2026-10-05: "turn notifications on or off ... individually
         * for each user"). It silences what INTERRUPTS, never the list: the
         * switch lives inside the list, so muting loses nothing.
         *
         * Read as "anything but an explicit false", so everybody who has never
         * touched it — every user that exists today — keeps their alerts.
         */
        // And per module (Jose, 2026-11-13: "choose notifications from specific
        // modules"): a muted module still lands in the list, it just does not
        // pop up, push or email.
        $module = $this->moduleOf($subject, $automationId, $eventId);
        $alertable = $told->filter(
            fn (User $user) => ($user->preferences['notifications.alerts'] ?? true) !== false
                && ($module === null || ! in_array($module, $user->preferences['notifications.muted_modules'] ?? [], true))
        )->values();

        $emailed = $this->mayEmail($source, $inAppOnly) ? $this->alsoByEmail($alertable, $title, $body, $scope, $this->pushHeader($scope, $subject)) : 0;

        /*
         * AND THE THIRD CHANNEL — the only one that reaches a closed app.
         *
         * `$inAppOnly` suppresses this too, and that is the key's NAME being
         * honoured rather than its label: "in-app only" means the bell and
         * nothing else. The switch Automations shipped reads "In-app only, do
         * not email", which was complete when email was the only other channel
         * and now names one of two — their wording to widen, not mine to
         * reinterpret.
         *
         * No separate ceiling, unlike email. MAX_EMAIL_RECIPIENTS exists
         * because a mailshot damages a sending reputation shared by every
         * organization on the platform and cannot be taken back; a push is
         * dismissed with a swipe and costs nobody else anything. The 200-row
         * cap above already bounds it.
         */
        $pushed = $inAppOnly ? 0 : $this->alsoByPush($alertable, $title, $body, $scope, $urgent || $this->isUrgent($source, $eventId), $rowIds, $this->pushHeader($scope, $subject));

        /*
         * HOW MANY PEOPLE WERE TOLD — on any channel, not how many rows exist.
         *
         * ActionRunner renders this to a rule author as "Told 5 people", so it
         * has to count people rather than artefacts. `max` rather than a sum
         * because the two sets overlap: everyone emailed was also belled unless
         * the bell is off, so adding them would report twice the audience.
         *
         * It is max rather than simply the row count because the row count is
         * zero when the bell is switched off, and "Told 0 people" is a lie told
         * to somebody whose 5 colleagues just received an email about it.
         */
        return max($rowsWritten, $emailed, $pushed);
    }

    /**
     * The same notification again, to the handsets of the people it is for.
     *
     * -- Why this counts DEVICES and returns PEOPLE --
     *
     * One person has a phone and a tablet and is told once, not twice. The
     * count that leaves this method is therefore the number of RECIPIENTS with
     * at least one device that accepted it, not the number of deliveries —
     * because dispatch() folds it into "how many people were told", and a
     * colleague with two handsets would otherwise inflate the sentence a rule
     * author reads.
     *
     * -- Why a missing credential is silence rather than a failure --
     *
     * The FCM service account lives on the box and is deliberately not in the
     * repository, so every developer machine legitimately lacks it. An absent
     * credential is a configuration state: no push channel, the bell and the
     * email unaffected, nothing thrown from inside a queue worker.
     *
     * @param  Collection<int, User>  $recipients
     * @return int recipients reached on at least one device
     */
    /**
     * Should this push interrupt — heads-up banner and sound on Android?
     *
     * Jose, 2026-10-08: HIGH importance for alerts, calls and new leads,
     * DEFAULT for the rest. Alerts are known by source. Leads and missed calls
     * arrive through rules (source `automation`, the same as every other rule),
     * so they are known by the event that raised them.
     */
    private function isUrgent(string $source, ?string $eventId): bool
    {
        if ($source === 'alert') {
            return true;
        }

        if ($eventId === null) {
            return false;
        }

        return in_array(
            AutomationEvent::query()->whereKey($eventId)->value('event_key'),
            ['lead.received', 'reports.call.missed', 'phone.call.missed'],
            true,
        );
    }

    /** @param array<string, string> $rowIds user id => that person's notification id */
    private function alsoByPush(Collection $recipients, string $title, ?string $body, Model $scope, bool $urgent = false, array $rowIds = [], string $header = 'EzBizi'): int
    {
        if (! $this->channelOn('notifications.push_enabled', $scope) || ! $this->push->configured()) {
            return 0;
        }

        $devices = DeviceToken::query()
            ->whereIn('user_id', $recipients->map->getKey()->all())
            ->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $reached = $devices
            ->groupBy('user_id')
            ->filter(fn (Collection $forOnePerson, $userId) => $this->push->send(
                $forOnePerson, $header, $body, $urgent, $rowIds[$userId] ?? null, $title,
            ) > 0)
            ->count();

        return $reached;
    }

    /**
     * The same notification again, as mail, when the organization asks for it.
     *
     * -- This is the half the settings screen has been promising --
     *
     * `notifications.email_enabled` has existed since August with a sentence
     * shown to administrators: "Takes effect when notifications can be sent by
     * email. They are only shown inside the app today, so switching this off
     * changes nothing." It was true until SES came out of the sandbox on
     * 2026-09-14 and Jose asked for the path to be built. Until then a send
     * reached only hand-verified addresses and reported success for every other
     * recipient, so wiring this earlier would have been worse than not having
     * it: silence that looks like delivery.
     *
     * -- Same audience, same ceiling, same scope, no second decision --
     *
     * It takes the collection dispatch() already resolved and already capped,
     * rather than asking who should hear a second time. Two derivations of "who
     * hears about this" is the disagreement `franchiseIdFor` exists to prevent
     * one level up, and here it would mail somebody the bell deliberately
     * skipped.
     *
     * The source toggles are not re-asked: allows() has already refused
     * everything it refuses, so a source switched off sends neither a row nor a
     * letter. `notifications.in_app_enabled` is NOT asked here at all — it
     * governs the row, and this is not the row. See dispatch().
     *
     * -- Why this has its own ceiling, lower than the bell's --
     *
     * MAX_RECIPIENTS is 200 and that number was chosen for ROWS. It does not
     * transfer. 200 rows is a bell showing 200; 200 emails is a mailshot, sent
     * per event, against an SES reputation that bounces and complaints damage
     * permanently and which no amount of later care repairs. Rows are cheap,
     * dismissible, and invisible outside the company. Mail is none of those.
     *
     * -- And why exceeding it sends NOTHING rather than the first fifty --
     *
     * Truncating would mail an arbitrary subset chosen by collection order, and
     * "why did Bob get an email about this and I did not" has no answer anybody
     * can give. A fan-out this wide is a misconfigured rule rather than a
     * legitimate audience, so the honest response is to leave it to the bell —
     * which told everybody — and say so loudly enough to be found.
     *
     * ponytail: one flat ceiling for every source. If a real audience ever
     * needs more than this, the fix is a per-source number or a digest, not a
     * bigger constant.
     *
     *
     * @param  Collection<int, User>  $recipients  already capped by the caller
     * @return int how many were actually emailed
     */
    private function alsoByEmail(Collection $recipients, string $title, ?string $body, Model $scope, ?string $header = null): int
    {
        if (! $this->channelOn('notifications.email_enabled', $scope)) {
            return 0;
        }

        if ($recipients->count() > self::MAX_EMAIL_RECIPIENTS) {
            Log::warning('Notification not emailed: audience above the email ceiling.', [
                'audience' => $recipients->count(),
                'ceiling' => self::MAX_EMAIL_RECIPIENTS,
                'title' => $title,
                'organization_id' => $this->tenant->id(),
            ]);

            return 0;
        }

        // Resolved here rather than inside the queued notice: reading it there
        // would read it at DELIVERY time, minutes after the decision it belongs
        // to, so a reply-to changed in between would apply retroactively to
        // mail that was already on its way.
        $replyTo = trim((string) $this->settings->get('notifications.reply_to_email', $scope));

        $notice = new NotificationNotice($title, $body, $replyTo === '' ? null : $replyTo, $header);

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                // notify() rather than the Notification facade: the model class
                // in this file is App\Models\Notification, and aliasing a facade
                // beside it to save one loop is how somebody later writes the
                // wrong one.
                //
                // withoutRelations() because this crosses into a QUEUE WORKER,
                // which has no tenant. The job records the recipient as an id
                // plus every relation loaded on it; the worker restores the User
                // unscoped but re-loads each relation THROUGH TenantScope, which
                // refuses with no tenant. Recipients arrive carrying
                // assignments from whatever reach check ran first — Fleet's
                // loadMissing() on the very instance passed as onlyTo — so
                // 7,705 mails died that way from 2026-09-16 to 2026-09-28
                // while the bell rows, inserted before this, all landed.
                // toMail() reads only ->name and the mail route only ->email,
                // so nothing is lost by sending the bare model.
                $recipient->withoutRelations()->notify($notice);
                $sent++;
            } catch (Throwable $exception) {
                /*
                 * CAUGHT BECAUSE THE BELL HAS ALREADY SUCCEEDED.
                 *
                 * The rows are inserted by the time this runs. Letting a mail
                 * failure escape would fail the automation action that raised
                 * it, and a failed action is RETRIED — re-entering dispatch()
                 * and inserting the whole audience's notifications a second
                 * time. A misconfigured mailer would show up as duplicate bells,
                 * which is a symptom nobody would trace back to email.
                 *
                 * Logged rather than swallowed, because this table records no
                 * delivery state at all: `notifications` has created_at and
                 * read_at and nothing else, so without this line a failed send
                 * leaves no trace anywhere in EzBizi. That is exactly the
                 * accepted-and-dropped silence the SES sandbox produced for
                 * three weeks, and it would be worse to ship it deliberately.
                 */
                Log::warning('Notification email failed for one recipient.', [
                    'user_id' => $recipient->getKey(),
                    'title' => $title,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /** One channel's own switch, resolved against the scope dispatch() chose. */
    private function channelOn(string $key, Model $scope): bool
    {
        return (bool) $this->settings->get($key, $scope);
    }

    /**
     * Narrow a reach-derived audience to the roles a rule named.
     *
     * ── It FILTERS. It never resolves an audience of its own ─────────
     *
     * Reach decides who COULD be told, exactly as it decides the job list (§4);
     * the role only decides which of those SHOULD be. Selecting users by role
     * directly would be a second derivation of "who hears about this", and the
     * way two derivations disagree is a notification sent to somebody who
     * cannot open the record it names — the leak this class's header exists to
     * prevent, arriving through the narrowing feature meant to reduce noise.
     *
     * So a rule can only ever take people away. It cannot reach past the
     * organization's own permission structure, for the same reason a rule can
     * decline the email but never demand one.
     *
     * ── Why an unknown role REFUSES rather than falls back ───────────
     *
     * The tempting fallback is "role gone, notify everyone with reach". That is
     * the exact failure this parameter was added to remove: Jose's live website
     * chat rule reaches all twelve people in the business today, and a rule
     * silently returning to that behaviour because somebody deleted a role
     * would be worse than the original, because it would look deliberate.
     *
     * InvalidArgumentException rather than a quiet zero because ActionRunner
     * already renders a thrown action error to the rule author, and it is the
     * idiom this path uses for configuration that cannot be honoured
     * ("notify needs a message."). A rule naming a role that no longer exists
     * is broken in a way only a person can fix, and they can only fix what they
     * are told about.
     *
     * ── Why IDs rather than names ────────────────────────────────────
     *
     * Roles are `organization_id` + `name` and the name is editable. Storing
     * names would break every rule the day somebody tidied "Office" into
     * "Office Staff", silently and in the widening direction. An id survives a
     * rename and fails loudly on a delete, which is the pair of behaviours
     * worth having.
     *
     * @param  Collection<int, User>  $recipients
     * @param  string[]  $roleIds  empty narrows nothing
     * @return Collection<int, User>
     */
    /**
     * One rule may tell one person at most FLOOD_CAP things per FLOOD_WINDOW.
     *
     * 2026-10-15: a looping "New reply" rule wrote 884 rows in 30 minutes. A
     * misfiring rule is a bug to fix at the rule; this keeps the bell usable
     * meanwhile. Automations only — system and alert rows are never dropped.
     */
    private const FLOOD_CAP = 20;

    private const FLOOD_WINDOW_MINUTES = 10;

    /**
     * Drop people who chose other companies (`notifications.companies`).
     *
     * Bar, 2026-10-22: "only Everdoors" means Everdoors, its sub companies
     * and their branches — so a record counts when ANY company above it is
     * chosen. A record in no company is organization-wide and reaches all.
     */
    private function onlyThoseFollowing(Collection $recipients, ?Model $subject, string $source, ?string $eventId): Collection
    {
        $choosers = $recipients->filter(fn (User $user) => ($user->preferences['notifications.companies'] ?? []) !== []);
        if ($choosers->isEmpty()) {
            return $recipients;
        }

        $chain = $this->companyChain($subject, $eventId);

        // No company known. An alert or system notice is organization-wide and
        // reaches everyone; an automation's (a missed call on an unmapped line)
        // is not "from Everdoors" for someone who chose only Everdoors —
        // measured 2026-10-23, Bar still got every company's missed calls.
        if ($chain === []) {
            return in_array($source, ['alert', 'system'], true)
                ? $recipients
                : $recipients->diff($choosers)->values();
        }

        return $recipients->filter(fn (User $user) => ($chosen = $user->preferences['notifications.companies'] ?? []) === []
            || array_intersect($chosen, $chain) !== [])->values();
    }

    /** The record's company and every company above it, nearest first. */
    private function companyChain(?Model $subject, ?string $eventId = null): array
    {
        $companyId = match (true) {
            $subject instanceof Company => $subject->getKey(),
            // A record-less event (a missed call) names its company in the payload.
            $subject === null => $eventId === null ? null
                : data_get(AutomationEvent::query()->find($eventId)?->payload, 'company_id'),
            default => $subject->getAttribute('company_id')
                ?? (($franchiseId = $this->franchiseIdFor($subject)) === null
                    ? null
                    : Franchise::query()->whereKey($franchiseId)->value('company_id')),
        };

        $chain = [];
        // ponytail: one query per level; company trees are a few levels deep.
        while (is_string($companyId) && ! in_array($companyId, $chain, true)) {
            $chain[] = $companyId;
            $companyId = Company::query()->whereKey($companyId)->value('parent_company_id');
        }

        return $chain;
    }

    /**
     * The kinds a person can switch off one by one, with the label shown in
     * bell settings. Task Board's (Jose, 2026-11-17: "every aspect of task
     * board is covered by notifications").
     */
    public const KINDS = [
        'task.assigned' => 'Assigned to me',
        'task.mentioned' => 'Mentioned',
        'task.reply' => 'Replies on my tasks',
        'task.status' => 'Status changes',
        'task.due' => 'Due soon and overdue',
        'task.subitem_assigned' => 'Subitems assigned',
        'task.moved' => 'Moved to my group',
        'task.file' => 'Files added',
        'task.created' => 'New tasks on my boards',
        // Project Management parts (Jose, 2026-11-17).
        'project_board.part_late' => 'A part is 3 days late',
        'project_board.part_reminder' => 'A part reminder is due',
        // HR (Merav, 2026-11-19).
        'hr.onboarding_missing' => 'Unfinished onboardings at my branch',
    ];

    /** Minutes within which a repeat of one kind on one record is one row. */
    private const KIND_BURST_MINUTES = 10;

    private function withoutMutedOrRepeatedKind(Collection $recipients, ?string $kind, ?Model $subject, string $title): Collection
    {
        if ($kind === null || $recipients->isEmpty()) {
            return $recipients;
        }

        $recipients = $recipients->reject(
            fn (User $user) => in_array($kind, $user->preferences['notifications.muted_kinds'] ?? [], true)
        )->values();

        if ($subject === null || $recipients->isEmpty()) {
            return $recipients;
        }

        // ponytail: grouped by subject+title, not a stored kind column; add one if two kinds share a title.
        $already = Notification::query()
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->where('title', $title)
            ->where('created_at', '>=', now()->subMinutes(self::KIND_BURST_MINUTES))
            ->whereIn('user_id', $recipients->map->getKey()->all())
            ->pluck('user_id')
            ->all();

        return $recipients->reject(fn (User $user) => in_array($user->getKey(), $already, true))->values();
    }

    /** The module a notification belongs to — the same reading the bell list uses. */
    private function moduleOf(?Model $subject, ?string $automationId, ?string $eventId): ?string
    {
        $eventKey = $eventId === null ? null : AutomationEvent::query()->whereKey($eventId)->value('event_key');

        return match (true) {
            $eventKey !== null => app(ModuleRegistry::class)->moduleKeyOfTrigger($eventKey),
            $automationId !== null => NotificationsController::AUTOMATIONS_MODULE,
            default => NotificationsController::SUBJECT_MODULES[$subject === null ? '' : $subject::class] ?? null,
        };
    }

    private function withoutFlooded(Collection $recipients, ?string $automationId): Collection
    {
        if ($automationId === null || $recipients->isEmpty()) {
            return $recipients;
        }

        $flooded = Notification::query()
            ->where('automation_id', $automationId)
            ->where('created_at', '>=', now()->subMinutes(self::FLOOD_WINDOW_MINUTES))
            ->whereIn('user_id', $recipients->map->getKey()->all())
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [self::FLOOD_CAP])
            ->pluck('user_id')
            ->all();

        return $flooded === []
            ? $recipients
            : $recipients->reject(fn (User $user) => in_array($user->getKey(), $flooded, true))->values();
    }

    private function narrowToChosen(Collection $recipients, array $roleIds, array $userIds = []): Collection
    {
        if ($roleIds === [] && $userIds === []) {
            return $recipients;
        }

        /*
         * A UNION, NOT AN INTERSECTION. "Tell the dispatchers, and also tell
         * Maria" is one sentence a person says; "tell the dispatchers who are
         * also Maria" is not, and would silently reach nobody whenever the two
         * lists disagreed — which is most of the time, since naming a person is
         * usually how somebody adds a name their roles do not cover.
         *
         * Still a NARROWING: reach decided who could be told before either list
         * is consulted, so naming somebody who cannot see the record does not
         * tell them. That is the direction Jose's own screen states — "only
         * those of them who can already see the record".
         */
        if ($userIds !== []) {
            $recipients = $recipients->filter(
                fn (User $user) => in_array($user->getKey(), $userIds, true)
                    || ($roleIds !== [] && in_array($user->role_id, $roleIds, true)),
            )->values();

            return $recipients;
        }

        // Tenant-scoped by BelongsToTenant, so a role id belonging to another
        // organization reads as missing here rather than matching.
        $known = Role::query()->whereKey($roleIds)->pluck('id')->all();
        $missing = array_values(array_diff($roleIds, $known));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'This rule notifies a role that no longer exists (%s). Nobody was told, '
                .'deliberately: notifying everybody instead would be the noise the role was '
                .'chosen to avoid. Edit the rule to name a role that exists.',
                implode(', ', $missing),
            ));
        }

        return $recipients
            ->filter(fn (User $user) => in_array($user->role_id, $roleIds, true))
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function audienceFor(?Model $subject): Collection
    {
        $users = User::query()->where('is_active', true)->with('assignments')->get();

        /*
         * A RECORD THAT KNOWS WHERE IT SITS IS ASKED, RATHER THAN GUESSED AT.
         *
         * The column sniff below finds a job and a customer and nothing else,
         * and until Human Resources raised events that was every record that
         * reached here. An invitation carries §4's XOR across three `assigned_*`
         * columns and an onboarding carries no scope column at all — so both
         * fell through to the organization-level branch, and a rule saying
         * "notify" on a completed onboarding would have told EVERY ACTIVE USER
         * in the business that a named person's contract had been signed.
         *
         * That is precisely the leak this class's own header says reach exists
         * to prevent, arriving through a record shape the sniff was never
         * written for. CarriesAutomationScope is the same question asked out
         * loud, so it is asked here as well as by the automation engine — one
         * answer, two readers, rather than a second derivation that can disagree
         * with who is allowed to open the record.
         *
         * A record with no franchise and no company belongs to the organization,
         * and here that means the people who STAND at the organization rather
         * than everybody in it — the same conservative direction Onboarding's
         * own scope() takes when it has nothing narrower to go on.
         */
        if ($subject instanceof CarriesAutomationScope) {
            [$type, $id] = $subject->automationScope();

            [$type, $id] = $type === null
                ? [Assignment::SCOPE_ORGANIZATION, $this->tenant->idOrFail()]
                : [$type, $id];

            return $users
                ->filter(fn (User $user) => $this->reach->covers($user, $type, $id))
                ->values();
        }

        $franchiseId = $this->franchiseIdFor($subject);

        if ($franchiseId === null) {
            // Nothing to scope by — an organization-level event. Everyone active
            // hears about it, which is right for "your trial ends tomorrow" and
            // is why this path exists at all.
            return $users;
        }

        $franchise = Franchise::query()->find($franchiseId);

        if ($franchise === null) {
            return collect();
        }

        // Reach, not membership. Assignment does not cascade but authority does
        // (§4), so a company-level manager hears about their Sub Companies'
        // franchises without being assigned to any of them.
        return $users->filter(fn (User $user) => $user->canActOnFranchise($franchise))->values();
    }

    /**
     * Is this organization sending this kind of notification at all?
     *
     * ── Why this is INSIDE dispatch rather than beside it ────────────
     *
     * It used to be a public `enabled()` that call sites were expected to ask
     * first, and its docblock claimed that putting it here "means a module added
     * later cannot forget to". That was never true of a method somebody has to
     * remember to call: of the three producers in the system, exactly one — the
     * HR controller — ever called it. An organization that switched in-app
     * notifications off went on receiving every automation notify and every task
     * assignment, and the switch that was supposed to stop them had been sitting
     * in the settings screen looking live since the day it shipped.
     *
     * So the check moved to the only place a caller cannot skip: the path the
     * row is written on. The sentence in the old docblock is now the truth
     * rather than the intention.
     */
    public function allows(string $source, ?Model $subject = null, bool $inAppOnly = false): bool
    {
        $scope = $this->settingsScope($subject);

        $key = self::SOURCE_SETTINGS[$source] ?? null;

        if ($key !== null && ! $this->channelOn($key, $scope)) {
            return false;
        }

        /*
         * AND AT LEAST ONE CHANNEL LEFT TO SAY IT ON.
         *
         * This is not the source question, and it is here for a caller outside
         * this file: ActionRunner:1194 asks allows() to explain a zero to a rule
         * author, choosing between "nobody has reach over that record" and "this
         * company has automation notifications switched off". Those send someone
         * debugging a rule to opposite places.
         *
         * Splitting in_app_enabled into a channel switch opened a third case —
         * source on, both channels off — and without this clause it would have
         * landed on the reach sentence, sending somebody to audit permissions
         * over a settings screen. Answering it here keeps that caller correct
         * without reaching into a file I do not own, and keeps allows() meaning
         * the one thing its name claims: will this be told at all.
         */
        /*
         * $inAppOnly NARROWS WHAT COUNTS AS "a channel left", and that is the
         * whole reason it is a parameter here and not only on dispatch().
         *
         * Automations asked for one argument. It needs two, because of a case
         * the first one opens on its own: a rule declining the email, in a
         * company whose bell is switched off and whose email is not. dispatch()
         * would write no row and send no letter and return 0 — and ActionRunner
         * asks THIS method to explain that zero, choosing between "nobody has
         * reach over that record" and "switched off for this company". Without
         * the flag, allows() would still see a live email channel, answer true,
         * and the rule author would be sent to audit permissions over a switch
         * they set themselves one screen away.
         *
         * Same shape as the third case the channel split opened, which is why
         * it is worth spelling out twice: every time this method learns a new
         * reason for nobody to be told, the caller that renders the zero has to
         * learn it in the same commit or it starts lying.
         */
        if ($inAppOnly) {
            return $this->channelOn('notifications.in_app_enabled', $scope);
        }

        /*
         * EVERY channel, and this list is the fourth time it has had to grow.
         *
         * It read `in_app` alone, then `in_app || email`, and adding push made
         * it wrong a third time in the same way: with the bell and the mail
         * both switched off and push on, this answered false and dispatch()
         * returned before the one channel that WAS available could fire. The
         * defect is silent every time, because a notification that never
         * happens looks exactly like one nobody had reach for.
         *
         * So the rule is written once here rather than as a boolean expression
         * that somebody has to remember to extend: any channel that can carry
         * this notification makes it worth resolving an audience for. A fourth
         * channel adds a line to the array and nothing else.
         */
        $channels = ['notifications.in_app_enabled'];

        if ($this->mayEmail($source, $inAppOnly)) {
            $channels[] = 'notifications.email_enabled';
        }

        foreach ($channels as $key) {
            if ($this->channelOn($key, $scope)) {
                return true;
            }
        }

        // Push carries the extra condition that the platform credential exists,
        // which the other two do not have: a server with no FCM service account
        // has the setting switched on and no way to act on it.
        return $this->channelOn('notifications.push_enabled', $scope) && $this->push->configured();
    }

    /**
     * Whether this notification may be emailed at all — asked by BOTH
     * dispatch() and allows(), so the two cannot disagree.
     *
     * An automation's notification is never emailed. Jose, 2026-09-28: "the
     * toggle should not exist at all, there is an automation for sending an
     * email ... if i wanted an automation to send an email i would use send an
     * email step". Email from a rule is only the "Send an email" step, which
     * sends its own mail and never comes through here.
     *
     * A SOURCE rule, not `$inAppOnly`, because that flag silences PUSH as well:
     * an automation passing it to stop the email would also stop the phone
     * buzzing, and a notification that silently never reaches a handset is the
     * failure this module has spent a month removing.
     */
    private function mayEmail(string $source, bool $inAppOnly): bool
    {
        return ! $inAppOnly && $source !== 'automation';
    }

    /**
     * Which scope's answer counts — the subject's franchise where there is one.
     *
     * Not always the organization. These settings are declared franchise-
     * overridable, so resolving every one of them against the organization would
     * leave a franchise's override switched on in the screen and dead in the
     * code — a second setting that looks live and is not, which is the whole
     * defect above repeated one level down.
     */
    /**
     * The first line of every push: whose notification it is (Jose,
     * 2026-10-21) — "EzBizi - A-1 Chimney - Connecticut", the branch dropped
     * when the record has none, the organization when it has no company.
     */
    /**
     * The same header for a record outside a dispatch — the bell list reads it
     * so the panel, the toast, the push and the mail all say one thing.
     */
    public function headerFor(?Model $subject): string
    {
        return $this->pushHeader($this->settingsScope($subject), $subject);
    }

    private function pushHeader(Model $scope, ?Model $subject): string
    {
        $companyId = $subject?->getAttribute('company_id');
        $company = is_string($companyId) ? Company::query()->find($companyId) : null;

        $parts = match (true) {
            $scope instanceof Franchise => [$scope->company?->name, $scope->name],
            $company !== null => [$company->name],
            default => [$scope->getAttribute('name')],
        };

        return implode(' - ', array_filter(['EzBizi', ...$parts], fn ($part) => is_string($part) && $part !== ''));
    }

    private function settingsScope(?Model $subject): Model
    {
        $franchiseId = $this->franchiseIdFor($subject);

        $franchise = $franchiseId === null ? null : Franchise::query()->find($franchiseId);

        return $franchise ?? $this->tenant->get() ?? throw new RuntimeException(
            'A notification needs a tenant in scope to resolve whether it is switched on.'
        );
    }

    /**
     * The franchise a record sits in, or null for one that sits above them all.
     *
     * Asked in one place because two readers need the same answer: who hears
     * about the record, and whose setting decides whether they hear at all. Two
     * derivations of "which franchise is this" could disagree, and the way they
     * would disagree is a notification sent under one franchise's rules to an
     * audience assembled under another's.
     */
    private function franchiseIdFor(?Model $subject): ?string
    {
        if ($subject instanceof CarriesAutomationScope) {
            [$type, $id] = $subject->automationScope();

            return $type === Assignment::SCOPE_FRANCHISE ? $id : null;
        }

        /*
         * A franchise is in itself, and nothing else here would have said so.
         *
         * The sniff below looks for a `franchise_id` column, which a Franchise
         * does not have — its own key IS the answer. Without this branch a
         * notification whose subject is a franchise reads as having no franchise
         * at all, and the two callers take that to mean the organization: the
         * setting resolves against the organization rather than the franchise's
         * own override, and the audience becomes EVERY ACTIVE USER rather than
         * the people with reach over that one franchise.
         */
        if ($subject instanceof Franchise) {
            return $subject->getKey();
        }

        return $subject?->getAttribute('franchise_id')
            ?? $subject?->getAttribute('owning_franchise_id');
    }
}
