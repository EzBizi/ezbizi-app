<?php

namespace App\Support\Access;

use App\Models\MailboxGrant;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The composition: a platform-wide permission catalogue, and this organization's
 * own inboxes, drawn as one tree (plan §4).
 *
 * ── What is being composed, and why it is two things ─────────────
 *
 * The owner wants one menu on screen:
 *
 *     Email
 *       * info@a-1chimney.com
 *       * billing@everdoors.com
 *
 * Those two lines come from opposite places. `Email` is a MODULE: declared in
 * code by the registry, identical for every tenant, and granted as a permission
 * key like every other tab. The addresses under it are TENANT RECORDS, different
 * for every organization and unknown to the software until somebody connects
 * one. Storing the second as the first would put one tenant's inbox addresses in
 * the platform `permissions` table that every other tenant's roles editor reads.
 *
 * So neither half moves. PermissionCatalogue keeps producing the static tree
 * exactly as it did — nothing in this file changes what a permission is — and
 * this class hangs the organization's own inboxes off the one module that owns
 * them. Take this class away and the roles editor is what it was.
 *
 * ── Where each half lives ────────────────────────────────────────
 *
 * The permission side is PermissionCatalogue and the module registry, both
 * untouched by this change. The mail side is the Email module's own records:
 * `Mailbox` and `MailboxGrant`, read here through InboxDirectory for the
 * addresses and used directly for the grants — the same table the Email module
 * asks when it decides whether to open a message, so a tick on the roles screen
 * and a refusal in the mailbox cannot disagree about what was granted.
 *
 * ── The rules it inherits, deliberately, rather than reinvents ───
 *
 * Every rule §4 states about a permission is true of an inbox grant, and each is
 * the same rule rather than a parallel one:
 *
 *   nobody grants what they do not hold      assertGrantable, below, bounded by
 *                                            what the ACTOR can open
 *   an omission is not a removal             preservedFor, below — the mirror of
 *                                            PermissionCatalogue::preservedFor
 *   Administrator holds everything           heldBy, below, expands to the whole
 *                                            list rather than reading its rows
 *   a grant with no tab grants nothing       assertReachable, below
 *
 * The last one is this feature's version of the implied-view invariant, and it
 * is enforced in the opposite direction. Granting an ACTION implies the module's
 * view, because "may edit, tab hidden" is a state nobody meant to create.
 * Granting an INBOX is refused when the tab is absent, because the owner's
 * requirement is that the two are visibly separate acts: silently switching the
 * Email tab on because somebody ticked an address would make granting the tab
 * and granting an inbox the same gesture, which is the thing this whole nested
 * list exists to keep apart.
 */
class InboxAccess
{
    public function __construct(
        private readonly InboxDirectory $directory,
        private readonly PermissionCatalogue $catalogue,
        private readonly ModuleRegistry $registry,
    ) {}

    /** `email` — the module the inboxes nest under. */
    public function moduleKey(): string
    {
        return $this->directory->moduleKey();
    }

    // ── the tree the editor is built from ────────────────────────

    /**
     * The static grantable tree, with this organization's inboxes hung off the
     * module that owns them.
     *
     * The `inboxes` key is present ONLY on that module, and being present with
     * an empty array is a different statement from being absent: absent means
     * this module has no inbox concept at all, empty means it does and the
     * organization has connected none. The client needs both, because the second
     * gets a sentence and the first gets nothing — and an empty nested list with
     * no explanation reads as a screen that failed to load.
     *
     * @param  array<int, array<string, mixed>>  $modules  from PermissionCatalogue::grantableTreeFor
     * @return array<int, array<string, mixed>>
     */
    public function compose(array $modules, User $actor): array
    {
        $owner = $this->moduleKey();
        $mine  = $this->heldByUser($actor);

        foreach ($modules as $index => $module) {
            if (($module['key'] ?? null) !== $owner) {
                continue;
            }

            $modules[$index]['inboxes'] = array_map(
                // Grantable per inbox, for the same reason `grantable` is per
                // action rather than per module: somebody who can only open
                // info@ may hand out info@ and nothing else.
                fn (GrantableInbox $inbox) => $inbox->toArray(
                    in_array($inbox->id, $mine, true)
                ),
                $this->directory->forOrganization($actor->organization),
            );
        }

        return $modules;
    }

    // ── what a role holds ────────────────────────────────────────

    /**
     * The inbox ids this role may open.
     *
     * Intersected with the inboxes that actually exist, for the reason
     * PermissionCatalogue intersects stored keys with the registry: a grant
     * naming an inbox the organization no longer has must grant nothing rather
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
     * while the module is off no new inbox can be granted to anybody. Read
     * openly, write closed — the same split §13 already uses.
     *
     * @return string[] mailbox ids
     */
    public function heldBy(Role $role): array
    {
        $existing = array_keys($this->directory->keyedByIdFor($role->organization_id));

        if ($role->isAdministrator()) {
            return $existing;
        }

        $granted = MailboxGrant::query()
            ->where('role_id', $role->getKey())
            ->pluck('mailbox_id')
            ->all();

        return array_values(array_intersect($existing, $granted));
    }

    /**
     * The same question for every role at once — one query rather than one per
     * row, because the roles list draws all of them.
     *
     * @param  iterable<Role>  $roles
     * @return array<string, string[]> role id → mailbox ids
     */
    public function heldByRoles(iterable $roles): array
    {
        /** @var Role[] $roles */
        $roles = is_array($roles) ? $roles : iterator_to_array($roles);

        if ($roles === []) {
            return [];
        }

        $held     = [];
        $existing = array_keys($this->directory->keyedByIdFor(reset($roles)->organization_id));

        foreach ($roles as $role) {
            $held[$role->getKey()] = [];
        }

        foreach (MailboxGrant::query()->whereIn('role_id', array_keys($held))->get() as $grant) {
            if (in_array($grant->mailbox_id, $existing, true)) {
                $held[$grant->role_id][] = $grant->mailbox_id;
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
     * @return string[] addresses, sorted
     */
    public function addressesHeldBy(Role $role): array
    {
        return $this->addresses(
            $this->heldBy($role),
            $this->directory->keyedByIdFor($role->organization_id),
        );
    }

    /**
     * What this PERSON can open — the bound on what they may hand out.
     *
     * Their role's grants, and the whole list for an administrator. A person
     * whose role cannot reach the Email tab holds no inbox whatever their grants
     * say, which is the same statement `heldBy` makes about a role: a grant
     * behind a tab you cannot open opens nothing.
     *
     * @return string[] mailbox ids
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
     * Refuse an inbox this caller cannot hand out.
     *
     * Two refusals, worded apart on purpose — the same discipline
     * PermissionCatalogue::assertGrantable follows. "That inbox is not one of
     * yours" and "you cannot open it yourself" are different problems with
     * different fixes, and one generic sentence sends somebody looking in the
     * wrong place.
     *
     * @param  string[]  $mailboxIds
     *
     * @throws ValidationException
     */
    public function assertGrantable(User $actor, array $mailboxIds): void
    {
        $mailboxIds = array_values(array_unique($mailboxIds));
        $inboxes    = $this->directory->keyedByIdFor($actor->organization);

        $unknown = array_values(array_filter(
            $mailboxIds,
            fn (string $id) => ! isset($inboxes[$id]),
        ));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['inboxes' => sprintf(
                '%d of the inboxes chosen %s no longer connected to this organization. '
                .'Reload the roles screen — somebody disconnected mail while this was open, '
                .'and it cannot be granted to anybody until it is connected again.',
                count($unknown),
                count($unknown) === 1 ? 'is' : 'are',
            )]);
        }

        $beyond = array_values(array_diff($mailboxIds, $this->heldByUser($actor)));

        if ($beyond !== []) {
            throw ValidationException::withMessages(['inboxes' => sprintf(
                'You cannot grant %s, because you cannot open %s yourself. Nobody can hand '
                .'out more than they have.',
                $this->readable($beyond, $inboxes),
                count($beyond) === 1 ? 'it' : 'them',
            )]);
        }
    }

    /**
     * Refuse an inbox granted to a role that cannot open the tab it lives on.
     *
     * The check runs AFTER the permissions on the same request have been saved,
     * because "does this role hold Email" is a question about the role as it will
     * be, not as it was. Ticking Email and an inbox under it in one edit is the
     * ordinary case and must go through.
     *
     * @param  string[]  $mailboxIds
     *
     * @throws ValidationException
     */
    public function assertReachable(Role $role, array $mailboxIds): void
    {
        if ($mailboxIds === [] || $this->reachesTheModule($role)) {
            return;
        }

        $module = $this->registry->get($this->moduleKey());

        throw ValidationException::withMessages(['inboxes' => $module === null
            ? 'This organization has no mail module, so there is no inbox to grant. '
                .'Nothing has been saved.'
            : sprintf(
                'This role cannot open %s, so an inbox granted to it would open nothing. '
                .'Tick %s itself first, then choose which inboxes it may read.',
                $module->label,
                $module->label,
            )]);
    }

    /**
     * The grants on a role this caller's edit must LEAVE ALONE.
     *
     * The mirror of PermissionCatalogue::preservedFor, and it exists for the same
     * reason: an inbox this person cannot open was never drawn as a live control
     * in their editor, so a submitted list that omits it is not a decision to
     * revoke it. Treating the omission as a revocation would let somebody quietly
     * cut a larger role off from mail they cannot see.
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
     * Replace this role's inbox grants, and report what moved — in ADDRESSES.
     *
     * The audit entry is the reason for the addresses. "Granted 0f8c-…" answers
     * nothing six months later; "granted billing@everdoors.com" is the sentence
     * somebody is actually looking for, and it survives the inbox being
     * disconnected afterwards, which an id does not.
     *
     * @param  string[]  $mailboxIds
     * @return array{added: string[], removed: string[]}
     */
    public function sync(Role $role, array $mailboxIds): array
    {
        $inboxes = $this->directory->keyedByIdFor($role->organization_id);

        $wanted = array_values(array_unique(array_filter(
            $mailboxIds,
            fn (string $id) => isset($inboxes[$id]),
        )));

        $before = $this->heldBy($role);

        $added   = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        DB::transaction(function () use ($role, $added, $removed) {
            if ($removed !== []) {
                MailboxGrant::query()
                    ->where('role_id', $role->getKey())
                    ->whereIn('mailbox_id', $removed)
                    ->delete();
            }

            foreach ($added as $mailboxId) {
                MailboxGrant::create([
                    'organization_id' => $role->organization_id,
                    'role_id'         => $role->getKey(),
                    'mailbox_id'      => $mailboxId,
                ]);
            }
        });

        return [
            'added'   => $this->addresses($added, $inboxes),
            'removed' => $this->addresses($removed, $inboxes),
        ];
    }

    // ── shared ───────────────────────────────────────────────────

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

    /**
     * @param  string[]  $ids
     * @param  array<string, GrantableInbox>  $inboxes
     * @return string[]
     */
    private function addresses(array $ids, array $inboxes): array
    {
        $addresses = array_map(fn (string $id) => $inboxes[$id]->address, $ids);

        sort($addresses);

        return $addresses;
    }

    /**
     * @param  string[]  $ids
     * @param  array<string, GrantableInbox>  $inboxes
     */
    private function readable(array $ids, array $inboxes): string
    {
        $addresses = $this->addresses($ids, $inboxes);

        return implode(', ', array_slice($addresses, 0, 5))
            .(count($addresses) > 5 ? sprintf(' and %d more', count($addresses) - 5) : '');
    }
}
