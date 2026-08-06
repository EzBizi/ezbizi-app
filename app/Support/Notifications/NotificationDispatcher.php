<?php

namespace App\Support\Notifications;

use App\Models\Franchise;
use App\Models\Notification;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Deciding who to tell, and telling them (plan §12).
 *
 * ── Why the audience is resolved from REACH ──────────────────────
 *
 * A workflow says "notify somebody about this job". The only defensible answer
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
        ?string $workflowId = null,
        ?string $eventId = null,
    ): int {
        $recipients = $onlyTo !== null
            ? collect([$onlyTo])
            : $this->audienceFor($subject);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $rows = $recipients->take(self::MAX_RECIPIENTS)->map(fn (User $user) => [
            'id'              => (string) \Illuminate\Support\Str::uuid7(),
            'organization_id' => $this->tenant->idOrFail(),
            'user_id'         => $user->getKey(),
            'source'          => $source,
            'title'           => $title,
            'body'            => $body,
            'subject_type'    => $subject ? $subject::class : null,
            'subject_id'      => $subject?->getKey(),
            'workflow_id'     => $workflowId,
            'event_id'        => $eventId,
            'created_at'      => now(),
        ])->all();

        // One insert. A loop of saves would be one query per recipient, and this
        // runs inside a queue worker handling every event on the platform.
        Notification::insert($rows);

        return count($rows);
    }

    /**
     * @return Collection<int, User>
     */
    private function audienceFor(?Model $subject): Collection
    {
        $franchiseId = $subject?->getAttribute('franchise_id')
            ?? $subject?->getAttribute('owning_franchise_id');

        $users = User::query()->where('is_active', true)->with('assignments')->get();

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
