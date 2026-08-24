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
 * Same composition as InboxAccess: a platform permission (the tab) and tenant
 * rows (which numbers this role may use). A grant behind a closed tab opens
 * nothing. Administrator holds every number without a grants row.
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
        $owner = $this->moduleKey();
        $mine = $this->heldByUser($actor);

        foreach ($modules as $index => $module) {
            if (($module['key'] ?? null) !== $owner) {
                continue;
            }

            $modules[$index]['phone_numbers'] = array_map(
                fn (GrantablePhoneNumber $item) => $item->toArray(in_array($item->id, $mine, true)),
                $this->directory->forOrganization($actor->organization_id),
            );
        }

        return $modules;
    }

    /** @return string[] */
    public function heldBy(Role $role): array
    {
        $existing = array_keys($this->directory->keyedByIdFor($role->organization_id));

        if ($role->isAdministrator()) {
            return $existing;
        }

        $granted = PhoneNumberGrant::query()
            ->where('role_id', $role->getKey())
            ->pluck('number_id')
            ->all();

        return array_values(array_intersect($existing, $granted));
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

        $held = [];
        $existing = array_keys($this->directory->keyedByIdFor(reset($roles)->organization_id));

        foreach ($roles as $role) {
            $held[$role->getKey()] = [];
        }

        foreach (PhoneNumberGrant::query()->whereIn('role_id', array_keys($held))->get() as $grant) {
            if (in_array($grant->number_id, $existing, true)) {
                $held[$grant->role_id][] = $grant->number_id;
            }
        }

        foreach ($roles as $role) {
            if ($role->isAdministrator()) {
                $held[$role->getKey()] = $existing;
            }
            sort($held[$role->getKey()]);
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

    public function isGranted(User $user, PhoneNumber $number): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        if ($user->role_id === null) {
            return false;
        }

        return PhoneNumberGrant::query()
            ->where('number_id', $number->getKey())
            ->where('role_id', $user->role_id)
            ->exists();
    }

    public function inView(User $user, PhoneNumber $number): bool
    {
        $view = ViewScope::forUser($user);

        if ($view->isOrganization()) {
            return true;
        }

        if ($view->type === ViewScope::COMPANY) {
            return match ($number->scope_type) {
                PhoneNumber::SCOPE_COMPANY => in_array($number->scope_id, $view->companyIds(), true),
                PhoneNumber::SCOPE_FRANCHISE => in_array($number->scope_id, $view->franchiseIds(), true),
                default => false,
            };
        }

        return $number->scope_type === PhoneNumber::SCOPE_FRANCHISE
            && $number->scope_id === $view->id;
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
