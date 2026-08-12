<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This role may open that inbox" (§4).
 *
 * The owner: *"the organization can grant access to the email module to a role,
 * but not necessary to all the emails connected to the module."* On the roles
 * screen that is one nested list —
 *
 *     Email
 *       * Email 1
 *       * Email 2
 *
 * — and what the reader sees is a single menu. What is UNDERNEATH it is two
 * different kinds of thing, and this class is the second.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  WHY THIS IS NOT A ROW IN `permissions`, AND WHY THAT IS NOT
 *  NEGOTIABLE.
 *
 *  `permissions` is a PLATFORM table. It is populated from the module
 *  registry — which is code, the same on every installation — and it
 *  is shared by every organization on the box. It has no
 *  `organization_id` and never will: the whole design is that a
 *  permission is a capability the SOFTWARE has, and roles reference
 *  them.
 *
 *  A permission row per mailbox would therefore write one tenant's
 *  inbox ADDRESSES into a table every other tenant's roles editor
 *  reads. `info@a-1chimney.com` would appear in the permission
 *  catalogue that Everdoors' administrator downloads. That is a
 *  tenant-boundary break, and not a subtle one — it is customer data
 *  in the control plane.
 *
 *  So per-inbox access is a TENANT-SCOPED GRANT: this table, carrying
 *  `organization_id` like every other tenant record, protected by
 *  BelongsToTenant's read filter and write stamp. It is the shape
 *  {@see Assignment} already uses for "where may this person act",
 *  applied to "which inbox may this role open".
 *
 *  The roles editor COMPOSES the two: the static `email.view`
 *  permission from the registry, and this organization's own
 *  mailboxes from here. Same menu on screen; boundary intact.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Against a ROLE, not a user ───────────────────────────────────
 *
 * Because the screen this appears on is the roles editor, and because the
 * organization's model of access is already "a role says what you may do". A
 * per-user grant would be a second, parallel system that the roles screen could
 * not show and that nobody would remember to revoke when somebody changed job.
 *
 * ── A grant is not reach, and BOTH are required ──────────────────
 *
 * Holding this row does not mean somebody may open the mailbox. They must also
 * REACH the level it hangs off — §4's authority rule — and the two questions are
 * genuinely different: a grant says "this role is trusted with the sales inbox",
 * reach says "this person's assignment covers the company that inbox belongs
 * to". App\Support\Email\MailboxAccess is where both are asked, once, and its
 * docblock works through the case that makes the distinction matter.
 */
class MailboxGrant extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * Configuration, and removed with either end.
     *
     * One row per (mailbox, role) pair the organization has decided on, capped
     * by the unique key. It does not accumulate: granting twice is granting
     * once, and revoking deletes the row rather than tombstoning it. Both
     * foreign keys cascade, so disconnecting a mailbox takes its grants and
     * deleting a role takes its own.
     *
     * `withParent()` rather than `forever()` because that is what actually
     * enforces it: there is no sweep and there does not need to be one — the
     * database removes the row when either thing it joins goes away. Who granted
     * what, and when, is the audit log, which does expire.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'One row for each role an organization has given access to a shared inbox. It '
            .'says who may open which mailbox rather than keeping a history of anything: it '
            .'is removed when the access is taken away, when the mailbox is disconnected, and '
            .'when the role is deleted. It holds no information about any person — a role is '
            .'not somebody\'s name — and no message. Who granted or revoked access, and when, '
            .'is in the audit log, which does expire.'
        );
    }

    protected $fillable = ['organization_id', 'mailbox_id', 'role_id'];

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
