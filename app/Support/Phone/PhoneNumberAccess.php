<?php

namespace App\Support\Phone;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phone lines nested under Phone on the roles editor, and the list filter for
 * the Call desk / Settings numbers screen.
 *
 * The tab is the whole gate. `phone.view` plus §4 reach decides which of the
 * organisation's lines a person sees; there is no per-number grant any more
 * (2 Sep — see [isGranted]), so a role with Communication gets its company's
 * numbers without anybody ticking them on one at a time.
 */
final class PhoneNumberAccess
{
    public function __construct(
        private readonly PhoneNumberDirectory $directory,
        private readonly PermissionCatalogue $catalogue,
        private readonly ModuleRegistry $registry,
    ) {}

    public function moduleKey(): string
    {
        return $this->directory->moduleKey();
    }

    /**
     * @param  array<int, array<string, mixed>>  $modules
     * @return array<int, array<string, mixed>>
     */
    public function compose(array $modules, User $actor): array
    {
        // Nothing. Phone lines are no longer a per-role grant, so the roles
        // screen must not draw a picker for them.
        //
        // Removing `isGranted` on 2 Sep left this still hanging every line onto
        // the Communication module, and the first attempt at a fix made the
        // picker report "7 of 7 phone lines granted" with every box ticked —
        // which is worse than the bug it replaced. It offered an administrator
        // seven controls that decide nothing: untick a line, save, and the role
        // still answers it. Jose's point when he asked for role-level access was
        // that a company with a hundred numbers cannot tick them one at a time;
        // a picker that shows all hundred pre-ticked and ignores every change
        // does not answer that, it just hides the answer.
        //
        // Access is now module permission ∩ scope ∩ §4 reach — see [isGranted]
        // and [mayView]. There is no fourth thing to tick, so there is no
        // section to render.
        return $modules;
    }

    /**
     * Every line in the organisation, for every role.
     *
     * Since 2 Sep a role is not granted lines one at a time — see [isGranted].
     * This method feeds the roles screen, so returning the old grant rows here
     * would draw a picker that no longer decides anything: tick a number,
     * change nothing. It answers what is actually true instead.
     *
     * Organisation-wide, not company-wide, because a Role belongs to an
     * organisation and has no company of its own. The narrowing to the
     * company and franchise a PERSON is assigned to happens in [mayView],
     * per user, where the assignment actually lives.
     *
     * @return string[]
     */
    public function heldBy(Role $role): array
    {
        return array_keys($this->directory->keyedByIdFor($role->organization_id));
    }

    /**
     * @param  iterable<Role>  $roles
     * @return array<string, string[]>
     */
    public function heldByRoles(iterable $roles): array
    {
        $roles = is_array($roles) ? $roles : iterator_to_array($roles);
        if ($roles === []) {
            return [];
        }

        // Every role holds every line in the organisation — grants are no
        // longer per number. See [heldBy] and [isGranted].
        $existing = array_keys($this->directory->keyedByIdFor(reset($roles)->organization_id));
        sort($existing);

        $held = [];
        foreach ($roles as $role) {
            $held[$role->getKey()] = $existing;
        }

        return $held;
    }

    /** @return string[] */
    public function labelsHeldBy(Role $role): array
    {
        return $this->labels($this->heldBy($role), $this->directory->keyedByIdFor($role->organization_id));
    }

    /** @return string[] */
    public function heldByUser(User $user): array
    {
        if (! $user->is_active || $user->role === null) {
            return [];
        }

        return $this->heldBy($user->role);
    }

    /**
     * Numbers Settings / the desk may list for this person.
     *
     * Same union as mailbox Settings: granted lines (phone.view ∩ grant ∩ view ∩
     * reach) plus lines they may manage (phone.edit ∩ view ∩ reach). Buyers must
     * see what they bought without waiting for a Roles grant.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function visibleTo(User $user): Collection
    {
        return $this->manageable($user)
            ->concat($this->readable($user))
            ->unique(fn (PhoneNumber $number) => $number->getKey())
            ->sortBy('e164')
            ->values();
    }

    /**
     * Granted lines for Call / Records — phone.view ∩ grant ∩ view ∩ reach.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function readable(User $user): Collection
    {
        if (! $user->hasPermission('phone.view')) {
            return collect();
        }

        return PhoneNumber::query()
            ->orderBy('e164')
            ->get()
            ->filter(fn (PhoneNumber $number) => $this->mayView($user, $number))
            ->values();
    }

    /**
     * Lines Settings may manage — phone.edit ∩ view ∩ reach, no grant.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function manageable(User $user): Collection
    {
        if (! $user->hasPermission('phone.edit')) {
            return collect();
        }

        return PhoneNumber::query()
            ->orderBy('e164')
            ->get()
            ->filter(fn (PhoneNumber $number) => $this->inView($user, $number)
                && $this->reaches($user, $number))
            ->values();
    }

    /**
     * Softphone / presence: phone.view and at least one granted number on
     * this Twilio connection (view-scope ∩ reach ∩ grant).
     */
    /**
     * May this person's desk register and take calls on this account: a line
     * there, or a place in one of its ring groups. Not narrowed by the view, as
     * calls arrive whichever company the screen shows (EverDoors' Owners: Jose
     * was a member, viewing A-1, and was refused, 2026-11-11).
     */
    public function takesCallsOn(User $user, PhoneConnection $connection): bool
    {
        if ($this->hasLineOn($user, $connection)) {
            return true;
        }

        return \App\Models\PhoneRingGroup::query()
            ->where('connection_id', $connection->getKey())
            ->get()
            ->contains(fn ($group) => in_array((string) $user->getKey(), DialPlan::memberUserIds($group), true));
    }

    public function hasLineOn(User $user, PhoneConnection $connection): bool
    {
        if (! $user->hasPermission('phone.view')) {
            return false;
        }

        return PhoneNumber::query()
            ->where('connection_id', $connection->getKey())
            ->get()
            ->contains(fn (PhoneNumber $number) => $this->mayView($user, $number));
    }

    /** Call / Records: tab + grant + view-scope + reach. */
    public function mayView(User $user, PhoneNumber $number): bool
    {
        if (! $user->hasPermission('phone.view')) {
            return false;
        }

        if (! $this->inView($user, $number)) {
            return false;
        }

        if (! $this->reaches($user, $number)) {
            return false;
        }

        return $this->isGranted($user, $number);
    }

    /**
     * Whether the role may use this line at all.
     *
     * ── Grants are no longer per number ──────────────────────────────
     *
     * This used to require a `phone_number_grants` row for (number, role), so
     * every line had to be ticked on for every role that should use it. Jose
     * ruled that out on 2 Sep: a role with access to Communication gets the
     * company's lines, full stop. A tenant heading for a hundred numbers
     * cannot be asked to tick a hundred boxes per role, and the failure is
     * silent when they miss one — the person sees an empty Dial screen and no
     * reason for it, which is exactly how a Dispatcher created ten minutes
     * before his grant landed appeared to have no phone system at all.
     *
     * This does NOT widen who sees what beyond their company. [mayView] has
     * already required `phone.view`, [inView] (the number's scope is one this
     * user can see) and [reaches] (§4 reach) before asking this — so the
     * bound is the company and franchise they are assigned to, which is the
     * bound Jose described. Only the per-number tick is gone.
     *
     * The table and its rows are left alone deliberately. Nothing reads them
     * for access now, so dropping them is a separate, reversible cleanup
     * rather than a migration bundled into a permissions fix.
     */
    public function isGranted(User $user, PhoneNumber $number): bool
    {
        return true;
    }

    /**
     * Delegated to PhoneScope so numbers and the things they point at answer
     * the same question the same way.
     *
     * This method used to hold its own copy of the rule. Ring groups, hours,
     * menus and voicemail boxes then grew their own filters — by REACH rather
     * than view — and a company started seeing a sibling's ring group. Five
     * copies of "may this company see it" is how the answers drift apart, and
     * this module has demonstrated that more than once.
     */
    public function inView(User $user, PhoneNumber $number): bool
    {
        return PhoneScope::visible(
            $user,
            (string) $number->scope_type,
            (string) $number->scope_id,
        );
    }

    public function reaches(User $user, PhoneNumber $number): bool
    {
        return $this->reachesScope($user, (string) $number->scope_type, (string) $number->scope_id);
    }

    public function reachesScope(User $user, string $scopeType, string $scopeId): bool
    {
        return match ($scopeType) {
            PhoneNumber::SCOPE_ORGANIZATION => $user->organization !== null
                && $scopeId === $user->organization_id
                && $user->canActOnOrganization($user->organization),

            PhoneNumber::SCOPE_COMPANY => ($company = Company::find($scopeId)) !== null
                && $user->canActOnCompany($company),

            PhoneNumber::SCOPE_FRANCHISE => ($franchise = Franchise::find($scopeId)) !== null
                && $user->canActOnFranchise($franchise),

            default => false,
        };
    }

    /** @param  string[]  $ids */
    public function assertGrantable(User $actor, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        $items = $this->directory->keyedByIdFor($actor->organization_id);

        $unknown = array_values(array_filter($ids, fn (string $id) => ! isset($items[$id])));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['phone_numbers' => sprintf(
                '%d of the phone numbers chosen %s no longer in this organization. '
                .'Reload the roles screen — somebody released one while this was open.',
                count($unknown),
                count($unknown) === 1 ? 'is' : 'are',
            )]);
        }

        $beyond = array_values(array_diff($ids, $this->heldByUser($actor)));
        if ($beyond !== []) {
            throw ValidationException::withMessages(['phone_numbers' => sprintf(
                'You cannot grant %s, because you cannot open %s yourself. Nobody can hand '
                .'out more than they have.',
                $this->asSentence($beyond, $items),
                count($beyond) === 1 ? 'it' : 'them',
            )]);
        }
    }

    /** @param  string[]  $ids */
    public function assertReachable(Role $role, array $ids): void
    {
        if ($ids === [] || $this->reachesTheModule($role)) {
            return;
        }

        $module = $this->registry->get($this->moduleKey());

        throw ValidationException::withMessages(['phone_numbers' => $module === null
            ? 'This organization has no Phone tab, so there is no phone number to grant. '
                .'Nothing has been saved.'
            : sprintf(
                'This role cannot open %s, so a phone number granted to it would open nothing. '
                .'Tick %s itself first, then choose which numbers it may use.',
                $module->label,
                $module->label,
            )]);
    }

    /** @return string[] */
    public function preservedFor(Role $role, User $actor): array
    {
        $mine = $this->heldByUser($actor);

        return array_values(array_filter(
            $this->heldBy($role),
            fn (string $id) => ! in_array($id, $mine, true),
        ));
    }

    /**
     * @param  string[]  $ids
     * @return array{added: string[], removed: string[]}
     */
    public function sync(Role $role, array $ids): array
    {
        $items = $this->directory->keyedByIdFor($role->organization_id);
        $wanted = array_values(array_unique(array_filter(
            $ids,
            fn (string $id) => isset($items[$id]),
        )));

        $before = $this->heldBy($role);
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        DB::transaction(function () use ($role, $added, $removed) {
            if ($removed !== []) {
                PhoneNumberGrant::query()
                    ->where('role_id', $role->getKey())
                    ->whereIn('number_id', $removed)
                    ->delete();
            }

            foreach ($added as $id) {
                PhoneNumberGrant::query()->create([
                    'organization_id' => $role->organization_id,
                    'number_id' => $id,
                    'role_id' => $role->getKey(),
                ]);
            }
        });

        return [
            'added' => $this->labels($added, $items),
            'removed' => $this->labels($removed, $items),
        ];
    }

    private function reachesTheModule(Role $role): bool
    {
        return in_array(
            $this->moduleKey().'.view',
            $this->catalogue->keysGrantedBy($role),
            true,
        );
    }

    /**
     * @param  string[]  $ids
     * @param  array<string, GrantablePhoneNumber>  $items
     * @return string[]
     */
    private function labels(array $ids, array $items): array
    {
        $labels = array_map(
            fn (string $id) => $items[$id]->label ?: $items[$id]->e164,
            $ids,
        );
        sort($labels);

        return $labels;
    }

    /**
     * @param  string[]  $ids
     * @param  array<string, GrantablePhoneNumber>  $items
     */
    private function asSentence(array $ids, array $items): string
    {
        $labels = $this->labels($ids, $items);

        return match (count($labels)) {
            0 => 'those phone numbers',
            1 => $labels[0],
            2 => $labels[0].' and '.$labels[1],
            default => $labels[0].', '.$labels[1].' and '.(count($labels) - 2).' more',
        };
    }
}
