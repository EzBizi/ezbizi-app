<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
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
 * lives on, how to reach it, the secret that opens it, whether it last worked,
 * and where the background watcher has read up to. It is not a mailbox in the
 * sense of holding mail — there is no messages table and there must not be one,
 * because reading is live (see App\Support\Email\MailboxReader).
 *
 * The watcher's mark is the one field that could look like the beginning of
 * such a table and is not: it is a POSITION the mail service issued — a Gmail
 * history id, an IMAP UID — carrying no subject, no sender and no text, and
 * meaningless without the provider that minted it. See the migration of
 * 2026-08-13, which makes that argument in full.
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
class Mailbox extends Model implements CarriesAutomationScope, HoldsSensitiveValues, StatesRetention
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
            .'from the mail service as it is shown, and is never written down here. The row '
            .'also keeps a bookmark saying how far EzBizi has read, so that a new message can '
            .'set off an automation without the same one setting it off twice; the bookmark is '
            .'a number the mail service issues and holds no subject, sender or text. Who '
            .'connected or changed a mailbox, and when, is in the audit log, which does expire.'
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
        'mark_read_at_source',
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

            /*
             * Off unless somebody chose it. A missing column on an old row and
             * "the customer said no" must read the same way, because the
             * consequence of getting it wrong is a shared inbox quietly
             * marking a colleague's unread mail as read.
             */
            'mark_read_at_source' => 'boolean',

            /*
             * When the mail service last actually answered.
             *
             * Cast so that the API publishes an ISO instant rather than whatever
             * string MySQL handed back, and so "has this ever worked" is
             * `!== null` rather than a comparison against the empty string.
             * Written in one place — see {@see markReached}.
             */
            'last_reached_at' => 'datetime',

            /*
             * The watcher's bookkeeping — see the migration of 2026-08-13 for
             * what each one means and why there are two timestamps rather than
             * one. Cast so that "is this mark stale" and "is this mailbox due"
             * are comparisons between instants rather than between strings.
             */
            'watch_marked_at'    => 'datetime',
            'watch_next_poll_at' => 'datetime',
            'send_checked_at'    => 'datetime',
            'watch_failures'     => 'integer',
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
     * A connection is checked within the minute: the watcher's next poll reads
     * the inbox and stamps `last_reached_at` or the failure sentence on the card.
     * A reconnect also drops the backoff a broken inbox built up -- up to six
     * hours of silence after Jose fixed it (11-12: "doing a connection should
     * take care of the whole process").
     */
    private const WATCH_NOW = ['watch_next_poll_at' => null, 'watch_failures' => 0, 'send_checked_at' => null];

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
            ...self::WATCH_NOW,
        ]);
    }

    /**
     * Mark it connected without storing a mailbox secret.
     *
     * Google Workspace mailboxes are opened by impersonating the address with
     * EzBizi's service account. That key lives in installation config, not on
     * this row — so "connected" here means "the address was picked from the
     * Directory", not "a refresh token arrived". {@see connect} remains the
     * only writer of `credentials`.
     */
    public function markConnectedWithoutSecret(): void
    {
        $this->forceFill([
            'status'        => self::STATUS_CONNECTED,
            'status_reason' => null,
            ...self::WATCH_NOW,
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
     *
     * `$reason` is a SENTENCE somebody reads, and the only caller —
     * {@see \App\Support\Email\RecordingMailboxReader} — takes it from
     * {@see \App\Support\Email\MailboxFault::sentence}. Never a driver message,
     * an OAuth error string or an exception class: those are written in somebody
     * else's vocabulary, they change without notice, and none of them says what
     * to do (R8).
     */
    public function markBroken(string $reason): void
    {
        $this->forceFill([
            'status'        => self::STATUS_BROKEN,
            'status_reason' => $reason,
        ]);
    }

    /**
     * The mail service just answered. Record when, and clear any old complaint.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE ONE CALLER IS RecordingMailboxReader, AND THAT IS THE
     *  WHOLE DESIGN.
     *
     *  This must be reachable from exactly one place, because the
     *  question `last_reached_at` answers — "when did this inbox last
     *  work?" — is destroyed by any second caller that is even
     *  slightly wrong. Stamp it when a read is ATTEMPTED and it says
     *  an inbox was reached at the moment it failed. Stamp it while
     *  DRAWING the settings screen — which asks every mailbox whether
     *  it can be opened — and every row reads "just now" for ever,
     *  because opening the tab is what refreshed them.
     *
     *  So it is not called from a controller, from a request, or from
     *  either reader. It is called by the wrapper that sits around
     *  every reader App\Support\Email\MailboxReaders hands out, after
     *  a call to the provider has RETURNED. A third provider added
     *  next year is wrapped by the same object and cannot forget,
     *  because it never has to remember.
     * ═══════════════════════════════════════════════════════════════
     *
     * ── It also HEALS the row, and that is not scope creep ───────
     *
     * A mailbox that answers is connected, whatever the last attempt concluded.
     * Leaving `status` at broken after a successful read would leave the settings
     * screen telling somebody to reconnect an inbox they are reading at that
     * moment — and `status_reason` would go on naming a cause that has passed. It
     * is the same argument {@see connect} makes for moving status with the
     * credential: two fields describing one fact must be written together, or one
     * of them becomes a stale opinion.
     */
    public function markReached(): void
    {
        $this->forceFill([
            'last_reached_at' => now(),
            'status'          => self::STATUS_CONNECTED,
            'status_reason'   => null,
        ]);
    }

    /** Has EzBizi ever actually opened this mailbox? */
    public function hasEverBeenReached(): bool
    {
        return $this->last_reached_at !== null;
    }

    // ── the watcher's position (see the 2026-08-13 migration) ────

    /**
     * Stand on a position the mail service just gave us.
     *
     * ═══════════════════════════════════════════════════════════════
     *  THE THREE FIELDS MOVE TOGETHER OR THE MARK IS A LIE.
     *
     *  The position, the moment it was confirmed, and the failure
     *  streak are one fact about one poll. Written separately, the
     *  first thing to go wrong is a mark advanced while
     *  `watch_marked_at` stays behind — which reads as stale on the
     *  next sweep, so the position is thrown away and re-taken, and
     *  everything between the two is never announced. Silently, and
     *  only under load.
     *
     *  Same argument {@see connect} makes for moving `status` with
     *  the credential, and {@see markReached} for healing the row: two
     *  fields describing one event must be written in one place.
     * ═══════════════════════════════════════════════════════════════
     *
     * The failure streak is cleared because a poll that answered ends it. It is
     * a count of CONSECUTIVE failures — a mailbox that fails, works, then fails
     * again has had one bad poll twice, not two in a row, and backing off for an
     * hour because of that would be punishing an inbox that is working.
     */
    public function standOn(string $mark): void
    {
        $this->forceFill([
            'watch_mark'      => $mark,
            'watch_marked_at' => now(),
            'watch_failures'  => 0,
        ]);
    }

    /**
     * Do not look at this mailbox again before `$when`.
     *
     * Said as an instant rather than as "in five minutes" because the caller is
     * the only thing that knows WHY it is waiting — the ordinary cadence, a
     * doubling backoff, or a mailbox that is skipped altogether — and a method
     * that computed the interval here would have to be told the reason anyway.
     */
    public function deferWatchUntil(\DateTimeInterface $when): void
    {
        $this->forceFill(['watch_next_poll_at' => $when]);
    }

    /** One more consecutive failure. Cleared by {@see standOn}. */
    public function noteWatchFailure(): void
    {
        $this->forceFill(['watch_failures' => (int) $this->watch_failures + 1]);
    }

    /**
     * Is the stored position too old to be believed?
     *
     * True when nothing has confirmed it for longer than `$seconds` — which
     * means the watcher was stopped, or this mailbox was skipped for that long.
     * Everything that arrived meanwhile is unaccounted for, and the only two
     * ways to handle unaccounted-for mail are to announce it all or none of it.
     * It is none of it: see App\Support\Email\Arrivals.
     *
     * A mailbox with no mark at all is NOT stale — it has never been marked, and
     * that is a different case with the same outcome. Kept separate so the log
     * can say which of the two happened, because "first sight of this inbox" and
     * "this inbox went unwatched for a day" want very different reactions from
     * whoever is reading it.
     */
    public function watchMarkHasGoneStale(int $seconds): bool
    {
        return $this->watch_mark !== null
            && ($this->watch_marked_at === null || $this->watch_marked_at->lt(now()->subSeconds($seconds)));
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

    // ── CarriesAutomationScope ───────────────────────────────────

    /**
     * A branch's own inbox belongs to that branch, and its mail with it.
     *
     * ── Why the engine could not have guessed this ───────────────
     *
     * `AutomationEngine::scopeOf` sniffs for `franchise_id` and
     * `owning_franchise_id`, and this row has neither: a mailbox carries §3's
     * `scope_type`/`scope_id` pair, exactly as a sending identity does, because
     * an inbox can hang off any of the three levels. So every event about a
     * mailbox reached the engine claiming to belong to the whole organization,
     * and a branch manager's rule — *"tell me when mail arrives at MY branch's
     * address"* — matched nothing at all, for the life of the rule, with nothing
     * anywhere saying why.
     *
     * This is stated rather than inferred for the same reason
     * {@see CarriesAutomationScope} was written: the model is the only thing
     * that knows.
     *
     * ── It is deliberately NOT the settings cascade ──────────────
     *
     * An organization-scoped inbox answers `[null, null]` — the whole
     * organization — so only an organization-wide rule covers it. It does NOT
     * claim to belong to every franchise beneath, which is the shape a cascade
     * would give it. That matters more here than almost anywhere: this row is
     * documented as the one thing in §3 that does not cascade, precisely so that
     * a branch cannot read the company's customer mail, and a rule at branch
     * level firing on company mail would hand them the sender and the subject of
     * every message in it.
     */
    public function automationScope(): array
    {
        return match ($this->scope_type) {
            self::SCOPE_COMPANY   => ['company', $this->scope_id],
            self::SCOPE_FRANCHISE => ['franchise', $this->scope_id],
            default               => [null, null],
        };
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
