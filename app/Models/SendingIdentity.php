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
 * 2026-09-14. Exactly ONE thing writes it now — {@see connectServer}, which sets
 * Verified because a tenant's own SMTP has nothing to prove to us — and no
 * request body can reach it: it is absent from $fillable, so a PATCH cannot
 * touch it whatever it is sent. That is the whole reason this is not a registry
 * setting, where any value IS settable by anybody with `organizations.edit`.
 *
 * (This paragraph said "NOTHING writes it now" until connectServer was added
 * minutes later in the same session. A comment goes stale from the change made
 * four lines below it, by the person who wrote both.)
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
             *
             * ── ITS OWN CREDENTIAL, NEVER A MAILBOX'S ────────────
             *
             * A Mailbox already holds exactly these five for replying as a
             * shared inbox, and reusing them here is the obvious saving. It is
             * refused, decided by the Email module on 2026-09-14 and recorded
             * HERE because this is where somebody stands when the idea occurs.
             *
             * The reason that survives every technical fix: the administrator
             * handed that password over on a screen about REPLYING AS that
             * inbox. A scheduled report is a different purpose and nobody
             * consented to it. Solving "which of info@, accounts@ and somebody's
             * personal address" with a picker would not touch that.
             *
             * And the one that is a defect rather than a preference, measured:
             * the details live in `mailboxes.mail_server` and the password in
             * `mailboxes.credentials`, both on a row carrying cascadeOnDelete in
             * FIVE tables. Deleting an inbox would silently take the report
             * sender's transport with it, and nothing anywhere would connect
             * those two facts for whoever deleted it.
             *
             * The convenience is an explicit "use the same server as info@…"
             * that COPIES these values at setup and says on screen that it
             * copied them — never an implicit fallback, because with a fallback
             * nobody can tell by LOOKING which credential is in use. A copy also
             * does not follow its source: change that mailbox's password later
             * and this row still holds the old one, which the screen has to
             * admit rather than implying a live link.
             */
            'credentials'     => 'encrypted:array',
            'verified_at'     => 'datetime',
            'last_checked_at'   => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    /**
     * THE ONLY METHOD THAT ASSIGNS TO `credentials`. One writer, one field.
     *
     * `forceFill` because `credentials` is deliberately not fillable, so no
     * request body can reach it through `update()` whatever it is sent — the
     * same shape {@see \App\Models\Mailbox::connect()} uses, for the same
     * reason and deliberately not a second dialect of it.
     *
     * The values are COPIED in, never referenced. When they came from a mailbox
     * the copy is its own from this moment: deleting that inbox takes nothing
     * with it, and changing its password does not change this. That is the whole
     * argument for copying rather than pointing, and the screen has to say so —
     * see the note on the cast above.
     *
     * @param  array{host:string,port:int,username:string,password:string,encryption:string}  $server
     */
    public function connectServer(array $server): void
    {
        $this->forceFill([
            'transport'   => MailTransport::Smtp->value,
            'credentials' => [
                'host'       => $server['host'],
                'port'       => $server['port'],
                'username'   => $server['username'],
                'password'   => $server['password'],
                'encryption' => $server['encryption'],
            ],

            /*
             * Verified, and it is not a lie by omission. `state` exists for the
             * retired domain-verification flow, where EzBizi had to prove a
             * claim about a domain before sending. Nothing is claimed here: the
             * tenant's own server authenticates the send with the password they
             * gave it, so MailTransport::Smtp->requiresDomainProof() is false
             * and canSend() never consults this column for these rows.
             *
             * Set rather than left null because it is NOT NULL, and because a
             * row sitting at a state that reads as "awaiting proof" would send
             * the next reader looking for a proof step that does not exist.
             */
            'state'           => SendingState::Verified->value,
            'state_reason'    => null,
            'verified_at'     => now(),
            'last_checked_at' => null,
        ]);
    }

    /**
     * Has anything ever been sent through this server?
     *
     * DERIVED, never asserted. The screen used to be going to say "saved, never
     * tried" as a fact about every row, which was true only because no sending
     * path existed — and the event that would falsify it lives in another
     * module. Reading the column means the sentence stops being true by itself
     * the first time a send records an attempt, with nobody having to remember.
     *
     * The inverse is NOT "it works". It says only that something has been tried,
     * which is why the screen carries its own admission that it cannot yet
     * report whether sending succeeds.
     */
    public function neverTried(): bool
    {
        return $this->last_attempted_at === null;
    }

    /**
     * Record that a send was attempted through this server, whatever came back.
     *
     * Called by the sending path on EVERY attempt, before the outcome is known,
     * so that a send which throws still counts as tried. An attempt recorded
     * only on success would leave a row that has failed ten times still saying
     * nobody has tried it, which is the exact sentence this column exists to
     * stop being a lie.
     */
    public function noteAttempt(): void
    {
        $this->forceFill(['last_attempted_at' => now()])->save();
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
