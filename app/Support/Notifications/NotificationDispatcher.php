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

    public function dispatchUnique(
        User $recipient,
        string $title,
        ?string $body,
        Model $subject,
        string $source,
        string $deliveryKey,
    ): Notification {
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

        $franchiseId = $subject?->getAttribute('franchise_id')
            ?? $subject?->getAttribute('owning_franchise_id');

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
     * Is this organization sending in-app notifications at all?
     *
     * The setting exists in the registry already. Checking it here rather than at
     * every call site means a module added later cannot forget to.
     */
    public function enabled(Model $scope): bool
    {
        return (bool) $this->settings->get('notifications.in_app_enabled', $scope);
    }
}
