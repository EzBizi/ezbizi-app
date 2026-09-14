<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Mail\MailIdentity;
use App\Support\Mail\MailTransport;
use App\Support\Mail\SendingState;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\MasksSensitiveValues;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One tenant's sending address, at one level of the hierarchy (§6, §2.1).
 *
 * A-1 Chimney holds info@a-1chimney.com at COMPANY level; a Connecticut branch
 * that wants info@ct.a-1chimney.com holds its own at FRANCHISE level; a branch
 * that holds nothing inherits whatever is above it. The walk is
 * {@see \App\Support\Mail\SendingIdentityResolver}, and the migration explains
 * why this is a table rather than a settings-registry entry.
 *
 * ── This model does not decide whether it may send ───────────────
 *
 * There is no `canSend()` here, deliberately. It converts into a
 * {@see MailIdentity} and that object answers, because the platform's identity
 * — which is deployment config and has no row — has to answer the same question
 * the same way. A copy on this class would be a second answer that cannot see
 * the platform link, and the two would drift the first time one of them was
 * corrected.
 *
 * ── And it does not decide its own verification state ────────────
 *
 * `state` was written by the domain-verification flow, which was deleted on
 * 2026-09-14. NOTHING writes it now, and no API route sets it — the
 * whole reason this is not a registry setting is that a registry value IS
 * settable by anybody with `organizations.edit` and a PATCH.
 */
class SendingIdentity extends Model implements HoldsSensitiveValues, StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use MasksSensitiveValues;

    /**
     * Configuration, not an accumulating record (§10).
     *
     * One row per level of the business that has chosen an address, capped by
     * the unique key on (scope_type, scope_id) — so this table's size is the
     * shape of the organization chart and not a function of anything anybody
     * does. A1-Group with two companies and five branches can hold eight rows.
     * There is nothing here for a sweep to trim without deleting a decision
     * somebody made and expects to still be in force.
     *
     * It goes when the organization does: `organization_id` cascades on delete,
     * so a removed tenant takes its addresses with it. Clearing one from the
     * screen deletes the row outright rather than tombstoning it — the record
     * of who changed it and when is the audit log, which does expire.
     *
     * ── Why NOT HoldsPersonalData ────────────────────────────────
     *
     * `from_address` and `reply_to` are addresses, and an address can identify
     * somebody — so this is worth stating rather than assuming. These two are
     * the business's OWN outward identity: the From line printed on every
     * message it sends to its customers, chosen by an administrator as the face
     * of the company. info@a-1chimney.com is a shop sign. A tenant who types a
     * named person's mailbox in here has published it to every recipient by
     * doing so, which is the opposite of the confidence §9 protects, and the
     * erasure of that person's own record is a separate thing this row is not
     * part of.
     *
     * `credentials` is not personal data either; it is a secret, and it is
     * handled as one — encrypted at rest and masked on every serialisation.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The address a business sends its own mail from, one row per level that has '
            .'chosen one. It is a setting rather than a record of events: it is capped by '
            .'the number of companies and branches in the organization, it holds no history, '
            .'and expiring it would silently move a customer back to sending as EzBizi. It '
            .'is deleted when the address is cleared, and with the organization when that '
            .'goes. Who changed it and when lives in the audit log, which does expire.'
        );
    }

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

    /**
     * What a person may set, and nothing else.
     *
     * `state`, `state_reason`, `verified_at`, `last_checked_at` and
     * `verification_token` are all absent on purpose — they are the system's
     * record of what it found, and a mass-assignable verification state is the
     * hole this whole design was arranged to close.
     */
    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'from_address', 'from_name', 'reply_to', 'transport',
    ];

    protected function casts(): array
    {
        return [
            /*
             * `encrypted:array`, and the window to choose the cast was still
             * open when it changed on 2026-09-14.
             *
             * The original note here was right that a cast is per-column and
             * cannot be switched on for rows that already exist, so the moment
             * to decide is before the first credential is written. What made the
             * change safe is not that note though — a docblock records what its
             * author believed — it is that `credentials` is absent from
             * $fillable and nothing in app/ or database/ assigns to it, so no
             * row on any box can hold a value to be misread. That holds for
             * servers I cannot reach, which counting rows on one does not.
             *
             * An array rather than a string because an SMTP connection is five
             * fields that must travel together — host, port, username, password,
             * encryption — and a delimited string is how one of them ends up
             * parsed differently in two places.
             */
            'credentials'     => 'encrypted:array',
            'verified_at'     => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    // ── the identity ─────────────────────────────────────────────

    /**
     * This row as the thing the rest of the application passes around.
     *
     * @param  string|null  $scopeName  what the level is CALLED — "A-1 Chimney",
     *                                  "Connecticut". Passed in rather than
     *                                  looked up, because the resolver already
     *                                  holds the scope models it walked and a
     *                                  lookup here would be one query per link.
     */
    public function toMailIdentity(?string $scopeName = null): MailIdentity
    {
        return new MailIdentity(
            fromAddress: $this->from_address,
            fromName:    $this->from_name,
            replyTo:     $this->reply_to,
            transport:   MailTransport::fromStored($this->transport),
            state:       $this->sendingState(),
            sourceType:  $this->scope_type,
            sourceId:    $this->scope_id,
            sourceName:  $scopeName,
            stateReason: $this->state_reason,
        );
    }

    /**
     * Named for the enum rather than for the column, and not `state()`.
     *
     * A method whose name matches a column is one Laravel release away from
     * being mistaken for an attribute accessor, and the failure would be silent:
     * `$identity->state` would start returning an enum where the rest of the
     * code expects the stored string.
     */
    public function sendingState(): SendingState
    {
        // Refuses rather than defaulting. A stored value this build does not
        // recognise must never fall through to Verified, and falling through to
        // Unverified would quietly un-verify a domain that is fine.
        return SendingState::from((string) $this->getAttribute('state'));
    }

    /** The domain half of the from address — what gets proved. */
    public function domain(): string
    {
        return strtolower(substr(strrchr((string) $this->from_address, '@') ?: '@', 1));
    }

    // ── HoldsSensitiveValues ─────────────────────────────────────

    /**
     * Unconditional, unlike OnboardingAnswer's.
     *
     * That model asks the tenant's own field definitions, because one row is
     * sensitive and the next is not. Here the answer never varies: a transport
     * credential is sensitive on every row there will ever be, so the list is
     * fixed and the interface is happy either way.
     */
    public function sensitiveAttributes(): array
    {
        return ['credentials'];
    }

    /**
     * Nobody, today.
     *
     * Not an oversight and not a placeholder. The only transport a tenant may
     * choose is verified-domain-on-our-transport, which has no credential, so
     * every row's `credentials` is null and there is nothing a reveal could
     * legitimately be for. Answering `false` means the one path that hands out a
     * clear value refuses with SensitiveValues' own sentence.
     *
     * When a bring-your-own-SMTP transport arrives this becomes a real check —
     * `organizations.edit` plus reach over this row's scope, the same pair
     * the controller used to apply to writes (deleted 2026-09-14).
     */
    public function mayReveal(User $actor): bool
    {
        return false;
    }
}
