<?php

namespace App\Support\Access;

/**
 * One of the organization's shared inboxes, as the roles editor needs to see it.
 *
 * ── Why the editor does not simply hold a Mailbox ────────────────
 *
 * The roles screen needs three facts about an inbox — what to call it, what to
 * store against a role, and whether the person editing may hand it out. A
 * mailbox record is a great deal more than that: a provider, a token, a
 * credential that unlocks somebody's entire email. None of that belongs in a
 * permissions tree, and a screen that holds the record holds all of it.
 *
 * So this is the narrow shape, and [InboxDirectory] is the one place that
 * produces it. Everything the roles editor knows about a tenant's mail is these
 * three fields.
 */
final class GrantableInbox
{
    public function __construct(
        /** The mailbox record's own id — what a grant is stored against. */
        public readonly string $id,
        /** `info@a-1chimney.com`. The one thing every inbox has. */
        public readonly string $address,
        /** What the organization named it, when it named it anything. */
        public readonly ?string $label = null,
    ) {}

    /**
     * Both fields go over the wire, and the client draws both.
     *
     * The address is never dropped in favour of the name, however good the name
     * is: "Support" and "Sales" are two words that could each be any of four
     * addresses, and the question the roles screen answers — which mail can this
     * role read — is unanswerable without the address in front of the person
     * answering it. Which of the two is the heading and which the second line is
     * a layout decision, so it is made where the layout is.
     *
     * @return array{id: string, address: string, label: string|null, grantable: bool}
     */
    public function toArray(bool $grantable): array
    {
        return [
            'id'        => $this->id,
            'address'   => $this->address,
            'label'     => $this->label,
            'grantable' => $grantable,
        ];
    }
}
