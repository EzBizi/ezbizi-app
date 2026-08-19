<?php

namespace App\Support\Inspection;

use App\Models\InspectionDefinitionGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Named inspections nested under Inspection on the roles editor.
 *
 * Same composition as InboxAccess: a platform permission (the tab) and tenant
 * rows (which named inspections this role may start). A grant behind a closed
 * tab opens nothing.
 */
final class InspectionGrantAccess
{
    public function __construct(
        private readonly InspectionDirectory $directory,
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

            $modules[$index]['inspections'] = array_map(
                fn (GrantableInspection $item) => $item->toArray(in_array($item->id, $mine, true)),
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

        $granted = InspectionDefinitionGrant::query()
            ->where('role_id', $role->getKey())
            ->pluck('inspection_definition_id')
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

        foreach (InspectionDefinitionGrant::query()->whereIn('role_id', array_keys($held))->get() as $grant) {
            if (in_array($grant->inspection_definition_id, $existing, true)) {
                $held[$grant->role_id][] = $grant->inspection_definition_id;
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
    public function namesHeldBy(Role $role): array
    {
        return $this->names($this->heldBy($role), $this->directory->keyedByIdFor($role->organization_id));
    }

    /** @return string[] */
    public function heldByUser(User $user): array
    {
        if (! $user->is_active || $user->role === null) {
            return [];
        }

        return $this->heldBy($user->role);
    }

    /** @param  string[]  $ids */
    public function assertGrantable(User $actor, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        $items = $this->directory->keyedByIdFor($actor->organization_id);

        $unknown = array_values(array_filter($ids, fn (string $id) => ! isset($items[$id])));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['inspections' => sprintf(
                '%d of the inspections chosen %s no longer in this organization. '
                .'Reload the roles screen — somebody removed one while this was open.',
                count($unknown),
                count($unknown) === 1 ? 'is' : 'are',
            )]);
        }

        $beyond = array_values(array_diff($ids, $this->heldByUser($actor)));
        if ($beyond !== []) {
            throw ValidationException::withMessages(['inspections' => sprintf(
                'You cannot grant %s, because you cannot offer %s yourself. Nobody can hand '
                .'out more than they have.',
                $this->readable($beyond, $items),
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

        throw ValidationException::withMessages(['inspections' => $module === null
            ? 'This organization has no Inspection tab, so there is no named inspection to grant. '
                .'Nothing has been saved.'
            : sprintf(
                'This role cannot open %s, so a named inspection granted to it would open nothing. '
                .'Tick %s itself first, then choose which inspections it may start.',
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
                InspectionDefinitionGrant::query()
                    ->where('role_id', $role->getKey())
                    ->whereIn('inspection_definition_id', $removed)
                    ->delete();
            }

            foreach ($added as $id) {
                InspectionDefinitionGrant::query()->create([
                    'organization_id' => $role->organization_id,
                    'inspection_definition_id' => $id,
                    'role_id' => $role->getKey(),
                ]);
            }
        });

        return [
            'added' => $this->names($added, $items),
            'removed' => $this->names($removed, $items),
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
     * @param  array<string, GrantableInspection>  $items
     * @return string[]
     */
    private function names(array $ids, array $items): array
    {
        $names = array_map(fn (string $id) => $items[$id]->name, $ids);
        sort($names);

        return $names;
    }

    /**
     * @param  string[]  $ids
     * @param  array<string, GrantableInspection>  $items
     */
    private function readable(array $ids, array $items): string
    {
        $names = $this->names($ids, $items);

        return match (count($names)) {
            0 => 'those inspections',
            1 => $names[0],
            2 => $names[0].' and '.$names[1],
            default => $names[0].', '.$names[1].' and '.(count($names) - 2).' more',
        };
    }
}
