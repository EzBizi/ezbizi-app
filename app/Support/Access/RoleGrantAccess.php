<?php

namespace App\Support\Access;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A tenant's own records nested under one module on the roles editor and
 * granted per role: inboxes under Email (InboxAccess, the first, whose class
 * docblock says why the two halves stay apart), named inspections under
 * Inspection, phone lines under Phone.
 *
 * Every rule here is a §4 permission rule applied to a tenant record, and each
 * is the same rule rather than a parallel one:
 *
 *   nobody grants what they do not hold      assertGrantable, bounded by what
 *                                            the ACTOR holds
 *   an omission is not a removal             preservedFor — the mirror of
 *                                            PermissionCatalogue::preservedFor
 *   Administrator holds everything           heldBy expands to the whole list
 *                                            rather than reading its rows
 *   a grant with no tab grants nothing       assertReachable
 *
 * A subclass names the grant table, the request field, how one record reads
 * in a sentence, and its own refusal sentences, word for word.
 */
abstract class RoleGrantAccess
{
    /** @var class-string<Model> the grant rows: organization_id, role_id, and COLUMN */
    protected const GRANT = Model::class;

    /** The grant column naming the record. */
    protected const COLUMN = '';

    /** The request field, and the key the records nest under in the tree. */
    protected const FIELD = '';

    /** "those …" — the sentence for no records at all. */
    protected const PLURAL = '';

    /**
     * The four refusals, each a whole sentence so it can be read and grepped:
     * `unknown` (sprintf: count, is/are), `beyond` (sprintf: the records,
     * it/them), `no_module`, and `unreachable` (sprintf: the tab label twice).
     *
     * @var array{unknown: string, beyond: string, no_module: string, unreachable: string}
     */
    protected const REFUSALS = ['unknown' => '', 'beyond' => '', 'no_module' => '', 'unreachable' => ''];

    public function __construct(
        protected readonly GrantDirectory $directory,
        protected readonly PermissionCatalogue $catalogue,
        protected readonly ModuleRegistry $registry,
    ) {}

    /** How one record reads in a sentence and an audit entry. */
    abstract protected function label(object $item): string;

    /** The module the records nest under. */
    public function moduleKey(): string
    {
        return $this->directory->moduleKey();
    }

    // ── the tree the editor is built from ────────────────────────

    /**
     * The static grantable tree, with this organization's records hung off the
     * module that owns them.
     *
     * The FIELD key is present ONLY on that module, and being present with an
     * empty array is a different statement from being absent: absent means
     * this module has no such records at all, empty means it does and the
     * organization has none. The client needs both, because the second gets a
     * sentence and the first gets nothing — and an empty nested list with no
     * explanation reads as a screen that failed to load.
     *
     * @param  array<int, array<string, mixed>>  $modules  from PermissionCatalogue::grantableTreeFor
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

            $modules[$index][static::FIELD] = array_map(
                // Grantable per record, for the same reason `grantable` is per
                // action rather than per module: somebody who can only open
                // info@ may hand out info@ and nothing else.
                fn (object $item) => $item->toArray(in_array($item->id, $mine, true)),
                $this->directory->forOrganization($this->organizationOf($actor)),
            );
        }

        return $modules;
    }

    // ── what a role holds ────────────────────────────────────────

    /**
     * The record ids this role holds.
     *
     * Intersected with the records that actually exist, for the reason
     * PermissionCatalogue intersects stored keys with the registry: a grant
     * naming a record the organization no longer has must grant nothing rather
     * than crash a screen, and it must not appear on one either.
     *
     * `mailbox_grants.mailbox_id` cascades on delete, so today that intersection
     * should never remove anything — the database takes the grants when the
     * mailbox goes. It is here because the guarantee this method makes is about
     * what it RETURNS rather than about what some other table does: a caller
     * reading a mailbox id out of this list must be able to resolve it, and that
     * has to stay true if the grant is ever written by an importer, restored
     * from a backup taken either side of a disconnection, or pointed at a
     * different store. It costs one array_intersect over a list already loaded.
     *
     * Administrator expands to the full list rather than reading its own rows,
     * exactly as it does for permissions. It owns none: `Role::hasPermission`
     * answers true for it unconditionally and nothing writes grants for it, so
     * reading its rows would report an administrator who can open no mail.
     *
     * ── It is NOT filtered by §13, and that is the existing contract ──
     *
     * A first draft returned nothing while the platform had the mail module
     * switched off, reasoning that a grant behind a tab nobody can open opens
     * nothing. True, and it is the wrong place to say it: RolesController@index
     * reports `permissionKeys()` for an ordinary role and the whole catalogue for
     * an administrator, neither of which is availability-filtered either. The
     * roles editor greys the MODULE from the catalogue's `available` flag and
     * shows what the organization decided underneath it, so an administrator
     * looking at a switched-off module can still see what they granted and what
     * will come back when it returns.
     *
     * Filtering here would have made the grants disappear from that screen
     * mid-incident, which reads as EzBizi having deleted them.
     *
     * The WRITE path is gated, and that is where it belongs: assertReachable asks
     * PermissionCatalogue::keysGrantedBy, which is availability-filtered, so
     * while the module is off no new record can be granted to anybody. Read
     * openly, write closed — the same split §13 already uses.
     *
     * @return string[] record ids
     */
    public function heldBy(Role $role): array
    {
        $existing = array_keys($this->directory->keyedByIdFor($role->organization_id));

        if ($role->isAdministrator()) {
            return $existing;
        }

        $granted = (static::GRANT)::query()
            ->where('role_id', $role->getKey())
            ->pluck(static::COLUMN)
            ->all();

        return array_values(array_intersect($existing, $granted));
    }

    /**
     * The same question for every role at once — one query rather than one per
     * row, because the roles list draws all of them.
     *
     * @param  iterable<Role>  $roles
     * @return array<string, string[]> role id → record ids
     */
    public function heldByRoles(iterable $roles): array
    {
        /** @var Role[] $roles */
        $roles = is_array($roles) ? $roles : iterator_to_array($roles);

        if ($roles === []) {
            return [];
        }

        $held = [];
        $existing = array_keys($this->directory->keyedByIdFor(reset($roles)->organization_id));

        foreach ($roles as $role) {
            $held[$role->getKey()] = [];
        }

        foreach ((static::GRANT)::query()->whereIn('role_id', array_keys($held))->get() as $grant) {
            if (in_array($grant->{static::COLUMN}, $existing, true)) {
                $held[$grant->role_id][] = $grant->{static::COLUMN};
            }
        }

        foreach ($roles as $role) {
            // Administrator owns no rows and holds everything anyway.
            if ($role->isAdministrator()) {
                $held[$role->getKey()] = $existing;
            }

            sort($held[$role->getKey()]);
        }

        return $held;
    }

    /**
     * The same list in words — for an audit entry, which is read by a person.
     *
     * @return string[] sorted
     */
    public function labelsHeldBy(Role $role): array
    {
        return $this->labels($this->heldBy($role), $this->directory->keyedByIdFor($role->organization_id));
    }

    /**
     * What this PERSON holds — the bound on what they may hand out.
     *
     * Their role's grants, and the whole list for an administrator. A person
     * whose role cannot reach the tab holds nothing whatever their grants say,
     * which is the same statement `heldBy` makes about a role: a grant behind a
     * tab you cannot open opens nothing.
     *
     * @return string[] record ids
     */
    public function heldByUser(User $user): array
    {
        if (! $user->is_active || $user->role === null) {
            return [];
        }

        return $this->heldBy($user->role);
    }

    // ── what a person may grant ──────────────────────────────────

    /**
     * Refuse a record this caller cannot hand out.
     *
     * Two refusals, worded apart on purpose — the same discipline
     * PermissionCatalogue::assertGrantable follows. "That record is not one of
     * yours" and "you cannot open it yourself" are different problems with
     * different fixes, and one generic sentence sends somebody looking in the
     * wrong place.
     *
     * @param  string[]  $ids
     *
     * @throws ValidationException
     */
    public function assertGrantable(User $actor, array $ids): void
    {
        $ids = array_values(array_unique($ids));
        $items = $this->directory->keyedByIdFor($this->organizationOf($actor));

        $unknown = array_values(array_filter($ids, fn (string $id) => ! isset($items[$id])));

        if ($unknown !== []) {
            throw ValidationException::withMessages([static::FIELD => sprintf(
                static::REFUSALS['unknown'],
                count($unknown),
                count($unknown) === 1 ? 'is' : 'are',
            )]);
        }

        $beyond = array_values(array_diff($ids, $this->heldByUser($actor)));

        if ($beyond !== []) {
            throw ValidationException::withMessages([static::FIELD => sprintf(
                static::REFUSALS['beyond'],
                $this->asSentence($beyond, $items),
                count($beyond) === 1 ? 'it' : 'them',
            )]);
        }
    }

    /**
     * Refuse a record granted to a role that cannot open the tab it lives on.
     *
     * The check runs AFTER the permissions on the same request have been saved,
     * because "does this role hold the tab" is a question about the role as it
     * will be, not as it was. Ticking the tab and a record under it in one edit
     * is the ordinary case and must go through.
     *
     * @param  string[]  $ids
     *
     * @throws ValidationException
     */
    public function assertReachable(Role $role, array $ids): void
    {
        if ($ids === [] || $this->reachesTheModule($role)) {
            return;
        }

        $module = $this->registry->get($this->moduleKey());

        throw ValidationException::withMessages([static::FIELD => $module === null
            ? static::REFUSALS['no_module']
            : sprintf(static::REFUSALS['unreachable'], $module->label, $module->label)]);
    }

    /**
     * The grants on a role this caller's edit must LEAVE ALONE.
     *
     * The mirror of PermissionCatalogue::preservedFor, and it exists for the same
     * reason: a record this person does not hold was never drawn as a live
     * control in their editor, so a submitted list that omits it is not a
     * decision to revoke it. Treating the omission as a revocation would let
     * somebody quietly cut a larger role off from what they cannot see.
     *
     * @return string[]
     */
    public function preservedFor(Role $role, User $actor): array
    {
        $mine = $this->heldByUser($actor);

        return array_values(array_filter(
            $this->heldBy($role),
            fn (string $id) => ! in_array($id, $mine, true),
        ));
    }

    // ── writing ──────────────────────────────────────────────────

    /**
     * Replace this role's grants, and report what moved — in WORDS.
     *
     * The audit entry is the reason for the words. "Granted 0f8c-…" answers
     * nothing six months later; "granted billing@everdoors.com" is the sentence
     * somebody is actually looking for, and it survives the record being
     * removed afterwards, which an id does not.
     *
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
                (static::GRANT)::query()
                    ->where('role_id', $role->getKey())
                    ->whereIn(static::COLUMN, $removed)
                    ->delete();
            }

            foreach ($added as $id) {
                (static::GRANT)::query()->create([
                    'organization_id' => $role->organization_id,
                    'role_id' => $role->getKey(),
                    static::COLUMN => $id,
                ]);
            }
        });

        return [
            'added' => $this->labels($added, $items),
            'removed' => $this->labels($removed, $items),
        ];
    }

    // ── shared ───────────────────────────────────────────────────

    /** The organization the actor's records are read for. */
    protected function organizationOf(User $actor): Organization|string
    {
        return $actor->organization_id;
    }

    /**
     * The records in words, for a refusal: two named, then a count.
     *
     * @param  string[]  $ids
     * @param  array<string, object>  $items
     */
    protected function asSentence(array $ids, array $items): string
    {
        $labels = $this->labels($ids, $items);

        return match (count($labels)) {
            0 => 'those '.static::PLURAL,
            1 => $labels[0],
            2 => $labels[0].' and '.$labels[1],
            default => $labels[0].', '.$labels[1].' and '.(count($labels) - 2).' more',
        };
    }

    /**
     * @param  string[]  $ids
     * @param  array<string, object>  $items
     * @return string[] sorted
     */
    protected function labels(array $ids, array $items): array
    {
        $labels = array_map(fn (string $id) => $this->label($items[$id]), $ids);

        sort($labels);

        return $labels;
    }

    /**
     * Can this role open the tab at all?
     *
     * Asked of PermissionCatalogue rather than of the pivot table directly, so
     * that the two things it knows and this class does not both apply:
     * Administrator holds the key without a row, and a module the PLATFORM has
     * switched off (§13) is held by nobody until it comes back.
     */
    private function reachesTheModule(Role $role): bool
    {
        return in_array(
            $this->moduleKey().'.view',
            $this->catalogue->keysGrantedBy($role),
            true,
        );
    }
}
