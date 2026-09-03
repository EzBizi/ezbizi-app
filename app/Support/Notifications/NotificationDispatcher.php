<?php

namespace App\Support\Notifications;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

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
    ): int {
        if (! $this->allows($source, $subject)) {
            return 0;
        }

        $recipients = $onlyTo !== null
            ? collect([$onlyTo])
            : $this->audienceFor($subject);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $rows = $recipients->take(self::MAX_RECIPIENTS)->map(fn (User $user) => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->tenant->idOrFail(),
            'user_id' => $user->getKey(),
            'source' => $source,
            'title' => $title,
            'body' => $body,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'automation_id' => $automationId,
            'event_id' => $eventId,
            'created_at' => now(),
        ])->all();

        // One insert. A loop of saves would be one query per recipient, and this
        // runs inside a queue worker handling every event on the platform.
        Notification::insert($rows);

        return count($rows);
    }

    /**
     * Null when this organization has the source switched off.
     *
     * Null rather than a silently-unsaved model: the caller holds a delivery
     * intent it has to settle either way, and "suppressed" and "delivered" are
     * different answers to give it. A Notification returned for a row that was
     * never written would have the intent recording a delivery that did not
     * happen — which is precisely the evidence that table exists to keep.
     */
    public function dispatchUnique(
        User $recipient,
        string $title,
        ?string $body,
        Model $subject,
        string $source,
        string $deliveryKey,
    ): ?Notification {
        if (! $this->allows($source, $subject)) {
            return null;
        }

        return Notification::query()->firstOrCreate([
            'user_id' => $recipient->id,
            'source' => $source,
            'delivery_key' => $deliveryKey,
        ], [
            'organization_id' => $this->tenant->idOrFail(),
            'title' => $title,
            'body' => $body,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'created_at' => now(),
        ]);
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
    public function allows(string $source, ?Model $subject = null): bool
    {
        $scope = $this->settingsScope($subject);

        if (! (bool) $this->settings->get('notifications.in_app_enabled', $scope)) {
            return false;
        }

        $key = self::SOURCE_SETTINGS[$source] ?? null;

        return $key === null || (bool) $this->settings->get($key, $scope);
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
