<?php

namespace App\Support\Access;

use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use Illuminate\Support\Collection;

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
 * the same rule rather than a parallel one. They live in RoleGrantAccess, which
 * named inspections and phone lines share; this class adds the nouns, the
 * sentences, and the per-person grants.
 *
 * The last one there (a grant with no tab grants nothing, assertReachable) is this feature's version of the implied-view invariant, and it
 * is enforced in the opposite direction. Granting an ACTION implies the module's
 * view, because "may edit, tab hidden" is a state nobody meant to create.
 * Granting an INBOX is refused when the tab is absent, because the owner's
 * requirement is that the two are visibly separate acts: silently switching the
 * Email tab on because somebody ticked an address would make granting the tab
 * and granting an inbox the same gesture, which is the thing this whole nested
 * list exists to keep apart.
 */
class InboxAccess extends RoleGrantAccess
{
    protected const GRANT = MailboxGrant::class;

    protected const COLUMN = 'mailbox_id';

    protected const FIELD = 'inboxes';

    protected const PLURAL = 'inboxes';

    protected const REFUSALS = [
        'unknown' => '%d of the inboxes chosen %s no longer connected to this organization. '
            .'Reload the roles screen — somebody disconnected mail while this was open, '
            .'and it cannot be granted to anybody until it is connected again.',
        'beyond' => 'You cannot grant %s, because you cannot open %s yourself. Nobody can hand '
            .'out more than they have.',
        'no_module' => 'This organization has no mail module, so there is no inbox to grant. '
            .'Nothing has been saved.',
        'unreachable' => 'This role cannot open %s, so an inbox granted to it would open nothing. '
            .'Tick %s itself first, then choose which inboxes it may read.',
    ];

    public function __construct(InboxDirectory $directory, PermissionCatalogue $catalogue, ModuleRegistry $registry)
    {
        parent::__construct($directory, $catalogue, $registry);
    }

    // ── per person (2026-10-06) ──────────────────────────────────

    /*
     * Jose: "I also want to be able to assign emails to specific people,
     * instead of just roles -- maybe done via the email settings tab".
     *
     * Written HERE, beside the role grants, so this class stays the one place
     * that writes `mailbox_grants`. The roles editor writes role rows; Email
     * settings writes person rows; both come through this file. The removed
     * second writer in MailboxesController is the reason that matters.
     *
     * Whether a person MAY be given an inbox (their role holds the Inbox tab,
     * the inbox is within their reach) is judged by the caller, which has
     * MailboxAccess; this only records the decision.
     */

    /** The people given this inbox directly, by name. */
    public function peopleOn(Mailbox $mailbox): Collection
    {
        return User::query()
            ->whereIn('id', MailboxGrant::query()
                ->where('mailbox_id', $mailbox->getKey())
                ->whereNotNull('user_id')
                ->select('user_id'))
            ->orderBy('name')
            ->get();
    }

    /** @return bool  true when newly given, false when they already had it */
    public function givePerson(Mailbox $mailbox, User $person): bool
    {
        return MailboxGrant::query()->firstOrCreate(
            ['mailbox_id' => $mailbox->getKey(), 'user_id' => $person->getKey()],
            ['organization_id' => $mailbox->organization_id],
        )->wasRecentlyCreated;
    }

    /** @return bool  true when taken away, false when they did not have it */
    public function takeFromPerson(Mailbox $mailbox, User $person): bool
    {
        return MailboxGrant::query()
            ->where('mailbox_id', $mailbox->getKey())
            ->where('user_id', $person->getKey())
            ->delete() > 0;
    }

    /** The roles that hold this inbox, by name -- for the screen to show beside the people. */
    public function rolesOn(Mailbox $mailbox): Collection
    {
        return Role::query()
            ->whereIn('id', MailboxGrant::query()
                ->where('mailbox_id', $mailbox->getKey())
                ->whereNotNull('role_id')
                ->select('role_id'))
            ->orderBy('name')
            ->get();
    }

    // ── shared ───────────────────────────────────────────────────

    /** The address: what every inbox has, and what an audit reader looks for. */
    protected function label(object $item): string
    {
        return $item->address;
    }

    protected function organizationOf(User $actor): Organization|string
    {
        return $actor->organization;
    }

    /**
     * Up to five addresses, then a count — an address is short enough to list.
     *
     * @param  string[]  $ids
     * @param  array<string, GrantableInbox>  $items
     */
    protected function asSentence(array $ids, array $items): string
    {
        $addresses = $this->labels($ids, $items);

        return implode(', ', array_slice($addresses, 0, 5))
            .(count($addresses) > 5 ? sprintf(' and %d more', count($addresses) - 5) : '');
    }
}
