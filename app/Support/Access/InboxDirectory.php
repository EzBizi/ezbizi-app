<?php

namespace App\Support\Access;

use App\Models\Mailbox;
use App\Models\Organization;

/**
 * THE ONE PLACE the roles editor learns what inboxes an organization has.
 *
 * ── Why the roles code does not simply query Mailbox ─────────────
 *
 * Two reasons, and the first is the one that made this a class rather than a
 * line in the controller.
 *
 * A mailbox record is a great deal more than an address. It carries a provider,
 * a connection, a status, and — for the IMAP case — an encrypted credential that
 * unlocks somebody's entire email. None of that has any business in a
 * permissions tree, and code that holds the record holds all of it. This class
 * exists so that everything the roles editor knows about a tenant's mail is
 * three fields: an id, an address, and a name if there is one.
 *
 * The second is that the Email module was built in parallel with this one, and
 * two agents inventing two contracts for the same record is how a previous round
 * lost a day. This file is the seam that was defined before the model existed
 * and pointed at it when it landed — which is what a seam is for. Everything
 * that follows about mail on the roles side goes through the four methods below.
 *
 * ── What it reads, and what it deliberately ignores ──────────────
 *
 *   id             what a grant is stored against
 *   address        info@a-1chimney.com — the one thing every inbox has
 *   display_name   what the organization named it, when it named it anything
 *
 * `status` is NOT read, and that is a decision rather than an oversight. A
 * mailbox whose credential has been revoked at the provider reports itself
 * broken, and it is still a mailbox this organization has: whether a role is
 * TRUSTED with the sales inbox is a different question from whether the
 * connection works this morning. Filtering broken ones out would make grants
 * disappear from the roles screen during exactly the incident when somebody is
 * looking at it, and reappear — or not — when the connection came back.
 *
 * Neither is `scope_type`/`scope_id`. A grant is against the role, which is
 * organization-wide (see Role: "a user's role does not vary per assignment"), so
 * the roles editor lists every inbox the organization has. Whether a particular
 * PERSON holding that role can reach the company the inbox hangs off is §4's
 * other half — reach — and it is asked where the mail is read, not here. Two
 * different questions, and collapsing them into this list would silently make a
 * grant mean something different depending on who was looking at the screen.
 *
 * ── The tenant filter is explicit as well as inherited ───────────
 *
 * Mailbox uses BelongsToTenant, so its reads are already scoped to the
 * organization in context. The `where` below is stated anyway, because one
 * tenant's inbox addresses appearing in another tenant's roles editor is the
 * exact failure the whole storage decision was made to prevent, and it would be
 * a quiet one — nothing errors, the wrong addresses simply appear. Belt and
 * braces on this particular query is cheap.
 */
class InboxDirectory implements GrantDirectory
{
    /**
     * The module these inboxes nest under in the roles editor.
     *
     * Named here rather than in the controller because it is the same fact as
     * everything else in this class: which module owns mail. Until the Email
     * module is declared in the registry there is no tab to nest anything under
     * and this key matches nothing — the editor draws what it always drew.
     */
    public const MODULE = 'email';

    public function moduleKey(): string
    {
        return self::MODULE;
    }

    /**
     * This organization's inboxes, in the order they should be read.
     *
     * By address rather than by name or by when it was connected: it is the
     * field every row has, it is what somebody scans the list for, and it gives
     * the same order to two people looking at the same screen.
     *
     * @return GrantableInbox[]
     */
    public function forOrganization(Organization|string $organization): array
    {
        return Mailbox::query()
            ->where('organization_id', $organization instanceof Organization
                ? $organization->getKey()
                : $organization)
            ->orderBy('address')
            ->get(['id', 'address', 'display_name'])
            ->map(fn (Mailbox $mailbox) => new GrantableInbox(
                id: (string) $mailbox->getKey(),
                address: (string) $mailbox->address,
                label: $mailbox->display_name,
            ))
            ->all();
    }

    /**
     * The same list keyed by id — what a grant is validated against.
     *
     * @return array<string, GrantableInbox>
     */
    public function keyedByIdFor(Organization|string $organization): array
    {
        return array_column($this->forOrganization($organization), null, 'id');
    }
}
