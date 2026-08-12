<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Email\MailboxProvider;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\MasksSensitiveValues;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared team inbox — one address several people answer (§3's levels, §4).
 *
 * The owner's purpose, in their words: *"the capacity to let people answer
 * emails they don't regularly have access to — like company wide emails that
 * receive customer emails or forms"*. info@a-1chimney.com is answered by four
 * people, none of whom has the password.
 *
 * ── What this row IS and what it is NOT ──────────────────────────
 *
 * It is a CONNECTION: an address, a name for the screen, which provider it
 * lives on, how to reach it, and the secret that opens it. It is not a
 * mailbox in the sense of holding mail — there is no messages table and there
 * must not be one, because reading is live (see App\Support\Email\MailboxReader).
 *
 * It is also NOT a sending identity. SendingIdentity is the address a business's
 * OUTBOUND mail goes out as — the From line on a newsletter — and it cascades
 * down the hierarchy so a branch that sets nothing inherits its company's. This
 * is the opposite kind of thing: an inbound mailbox somebody has to be granted,
 * and it deliberately does NOT cascade. A franchise inheriting its company's
 * inbox would mean every branch could read the company's customer mail the
 * moment somebody connected it.
 *
 * ── The credential is the highest-value secret in the system ─────
 *
 * An OAuth refresh token or an IMAP password unlocks somebody's ENTIRE email:
 * every message they have ever received, every password-reset link that has ever
 * been sent to them, and the ability to send as them. That is a bigger prize
 * than anything else this application stores, including payroll onboarding
 * answers.
 *
 * Three things hold it, and each is load bearing:
 *
 *   1. `encrypted` on the column, so a database dump is not a credential dump.
 *   2. {@see MasksSensitiveValues}, so every `toArray()`, `toJson()` and
 *      `response()->json($mailbox)` — including inside an eager-loaded relation
 *      — publishes the mask rather than the value.
 *   3. {@see mayReveal} answers FALSE for everybody, so the one audited path
 *      that hands out a clear value refuses. See its own note: this is not a
 *      placeholder.
 */
class Mailbox extends Model implements HoldsSensitiveValues, StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use MasksSensitiveValues;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

    /** Connected and working, as far as the last attempt could tell. */
    public const STATUS_CONNECTED = 'connected';

    /** Added, but nobody has signed in or entered a password yet. */
    public const STATUS_UNCONFIGURED = 'unconfigured';

    /** It worked and now does not — the password changed, or consent was withdrawn. */
    public const STATUS_BROKEN = 'broken';

    /**
     * Configuration, not an accumulating record (§10).
     *
     * One row per address an organization has connected, capped by the unique
     * key on (organization_id, address) — so this table's size is a decision
     * somebody made, not a function of anything anybody does. A business with
     * info@, sales@ and a branch address has three rows, for ever.
     *
     * `forever()` rather than a window, because there is no age at which a live
     * connection stops being current: expiring it would silently disconnect a
     * mailbox somebody is answering customer mail in. It goes when the
     * organization goes — `organization_id` cascades — and disconnecting one
     * deletes the row outright rather than tombstoning it. Who connected it and
     * when is the audit log, which does expire.
     *
     * ── Why NOT HoldsPersonalData ────────────────────────────────
     *
     * The same argument SendingIdentity makes, and it is worth restating because
     * an inbox feels more personal than an outbound address. `address` here is
     * the business's own shop sign — info@, sales@, bookings@ — chosen by an
     * administrator as a place for the public to write to. It is not an
     * individual's mailbox: decision 1 is that this module is for SHARED team
     * inboxes, and a personal mailbox is explicitly out of scope.
     *
     * The MESSAGES in it are full of other people's personal data, and that is
     * precisely why none of them is stored. Nothing to declare follows from
     * nothing being kept.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The connection to a shared inbox — its address, which mail service it is on, and '
            .'the key that opens it. One row per address the organization has connected, held '
            .'for as long as it is connected and deleted when somebody disconnects it. No '
            .'message, no attachment and no message content is stored anywhere: mail is read '
            .'from the mail service while somebody is looking at it and is never written down '
            .'here. Who connected or changed a mailbox, and when, is in the audit log, which '
            .'does expire.'
        );
    }

    /**
     * What a person may set.
     *
     * `credentials` is absent, and that is the point rather than an oversight:
     * the secret is written by {@see connect} and by nothing else, so no request
     * body — however it is shaped, whatever a controller passes to `fill()` —
     * can reach it. `status` and `status_reason` are absent for the same reason
     * SendingIdentity keeps `state` unfillable: a mailbox that can be declared
     * connected over HTTP has a connection check that proves nothing.
     */
    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'address', 'display_name', 'provider', 'mail_server',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Encrypted on every row from the first row.
             *
             * A cast is per-column and cannot be switched on for rows that
             * already exist, so the moment to decide is before any credential is
             * written rather than after somebody notices. Same argument as
             * OnboardingAnswer::$value and SendingIdentity::$credentials.
             */
            'credentials' => 'encrypted',
            'mail_server' => 'array',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    public function grants(): HasMany
    {
        return $this->hasMany(MailboxGrant::class);
    }

    public function seen(): HasMany
    {
        return $this->hasMany(MessageSeen::class);
    }

    // ── shape ────────────────────────────────────────────────────

    /** Null when the stored value is one this build does not recognise. */
    public function providerEnum(): ?MailboxProvider
    {
        return MailboxProvider::tryFromStored($this->provider);
    }

    public function hasCredential(): bool
    {
        return ($this->getAttribute('credentials') ?? '') !== '';
    }

    /**
     * The secret, in the clear, for the code that is about to spend it.
     *
     * Named `credential()` rather than exposed as an attribute so that every use
     * is greppable, and deliberately NOT routed through SensitiveValues::read():
     * that call is the AUDITED DISCLOSURE path — it exists to record that a
     * human being was shown a value — and a reader opening a mailbox is not a
     * disclosure to anybody. Writing an audit row per page of an inbox would
     * bury the entries that matter under thousands that do not.
     *
     * What a person asking to SEE it gets is {@see mayReveal}, which is no.
     */
    public function credential(): ?string
    {
        return $this->getAttribute('credentials');
    }

    /**
     * Write the secret. The only path that does.
     *
     * `forceFill` because `credentials` is deliberately not fillable — see
     * `$fillable`. Status moves with it, because a credential arriving IS the
     * connection being made and leaving the two to be set separately is how a
     * row ends up holding a working token while the screen says unconfigured.
     */
    public function connect(string $credential): void
    {
        $this->forceFill([
            'credentials'   => $credential,
            'status'        => self::STATUS_CONNECTED,
            'status_reason' => null,
        ]);
    }

    /**
     * The mail service refused us, and somebody has to be told.
     *
     * The row is kept rather than deleted. A mailbox that vanished when its
     * password changed would take its GRANTS with it — the cascade in the
     * migration is real — so four people would silently lose access to an
     * address that is still receiving customer mail, and reconnecting would mean
     * granting them all again.
     */
    public function markBroken(string $reason): void
    {
        $this->forceFill([
            'status'        => self::STATUS_BROKEN,
            'status_reason' => $reason,
        ]);
    }

    /**
     * Where the IMAP server is, or null for a provider that has no host.
     *
     * Returned as a small array rather than as three accessors so that a caller
     * cannot use the host without the port — which is exactly the shape that
     * lets somebody pass a guarded host to an unguarded default port.
     *
     * @return array{host: string, port: int, encryption: string}|null
     */
    public function host(): ?array
    {
        return $this->endpoint('imap', 993);
    }

    /**
     * Where replies go out through.
     *
     * @return array{host: string, port: int, encryption: string}|null
     */
    public function smtpHost(): ?array
    {
        return $this->endpoint('smtp', 587);
    }

    /**
     * @return array{host: string, port: int, encryption: string}|null
     *
     * ── `getAttribute()` rather than `$this->mail_server` ────────
     *
     * Belt and braces after a real failure. The column used to be called
     * `connection`, which collides with Eloquent's own protected
     * `$connection` property — so inside the model `$this->connection` returned
     * the DATABASE connection name rather than the attribute, silently, as
     * null. The consequence was not cosmetic: this method returned null, the
     * SSRF guard in MailboxesController::connect had no host to judge, and a
     * mailbox pointed at the cloud metadata endpoint was accepted. The test that
     * caught it is MailboxesApiTest's metadata case.
     *
     * The column was renamed, which fixes it. Reading through `getAttribute()`
     * is the second lock: it goes to the attribute bag whatever the column is
     * called, so the same collision cannot come back through a future rename.
     */
    private function endpoint(string $which, int $defaultPort): ?array
    {
        $settings = $this->getAttribute('mail_server') ?? [];
        $host     = $settings["{$which}_host"] ?? null;

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        return [
            'host' => trim($host),
            'port' => (int) ($settings["{$which}_port"] ?? $defaultPort),
            // Only ever read to decide how to open a socket, never sent
            // anywhere. `tls` is the sane default: a mail connection that is not
            // encrypted sends the password across the network in the clear.
            'encryption' => (string) ($settings["{$which}_encryption"] ?? 'tls'),
        ];
    }

    // ── HoldsSensitiveValues ─────────────────────────────────────

    /**
     * Unconditional, like SendingIdentity's and unlike OnboardingAnswer's.
     *
     * A mailbox credential is sensitive on every row there will ever be, so the
     * list does not vary and the interface is satisfied either way.
     */
    public function sensitiveAttributes(): array
    {
        return ['credentials'];
    }

    /**
     * NOBODY, including the administrator who typed it. Not a placeholder.
     *
     * The reveal path exists so that somebody entitled can read back a value
     * they legitimately need to see — a bank account on a contract, so it can be
     * checked against a payslip. There is no such need here, and the reasons are
     * worth writing down because "let an administrator see what they entered"
     * sounds reasonable until it is examined:
     *
     *   1. IT WOULD BE USED ELSEWHERE. This is not a reference number; it is a
     *      working key to somebody's entire email. Revealed once, it can be
     *      pasted into any mail client on any laptop, and nothing here would
     *      ever know.
     *   2. THERE IS NOTHING TO CHECK IT AGAINST. A wrong password produces a
     *      failed connection with a sentence saying so; reading the stored one
     *      back does not diagnose anything the connection test does not.
     *   3. RE-ENTERING IT IS THE FIX, AND IT IS CHEAP. Somebody who has lost the
     *      password re-enters it or reconnects with Google. One form, and the
     *      old value is replaced rather than exposed.
     *
     * The consequence is deliberate: a credential goes IN and never comes back
     * out through any API path this application has.
     */
    public function mayReveal(User $actor): bool
    {
        return false;
    }
}
