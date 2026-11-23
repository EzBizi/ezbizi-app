<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

/**
 * An offer of an account, with the role and the place already decided (§4).
 *
 * ── Status is derived, never stored ──────────────────────────────
 *
 * The plan names six: sent · received · in progress · completed · abandoned ·
 * revoked. Five of them are a question about the timestamps on this row, and
 * "abandoned" is mostly a question about the CLOCK — a week has passed and
 * nobody came.
 *
 * Storing it would mean a scheduled job whose only purpose is to make a column
 * agree with `now()`. When such a job stops, every expired invitation goes on
 * reporting "sent", so the screen says a dead link is live and somebody chases
 * a person who cannot get in. Derivation cannot drift, so there is no sweep in
 * routes/console.php for this and none is needed.
 *
 * `in progress` used to be the exception, declared with nothing able to produce
 * it because the onboarding form did not exist. It does now, and this is its
 * producer: an invitation reports `in_progress` once an onboarding has been
 * started against it — the employer has picked a contract and the form is open
 * — and goes on to `completed` when the invitation is accepted.
 *
 * ── Where it sits in the order, which is a real decision ─────────
 *
 * BELOW expiry, not above it. An invitation whose week ran out while a form sat
 * half-filled is `abandoned`, because the link is dead and a resend is what
 * fixes it; reporting it as `in_progress` would show a live-looking row in a
 * list whose entire purpose is telling somebody what still needs chasing. The
 * form is not lost — it is pinned to its contract version and waiting — and the
 * invitation returns to `in_progress` the moment it is resent.
 *
 * ── The cost of reading a relation from a derived status ─────────
 *
 * `status()` now touches the database when `onboarding` is not already loaded.
 * That is a lazy load per row in any list that forgets to eager-load it. The
 * alternative is a stored column, and the argument against one has not changed:
 * this status would then need to be written by whatever creates an onboarding,
 * and the day something creates one without writing it, the list is wrong with
 * nothing saying so. A query is cheaper to pay than a lie.
 *
 * ── Revoked is its own status, decided 2026-08-11 ────────────────
 *
 * It was first collapsed into `abandoned`, with `revoked_at` carried beside it
 * so a screen could still tell the two apart by hand. That was wrong, and the
 * owner settled it: "we changed our mind" and "they never came" are different
 * facts about different failures. A list that reports them as one word cannot
 * answer the question the Invitations tab exists to answer — whether the
 * invitations being sent are being ignored — because every withdrawal inflates
 * the same number.
 *
 * The distinction is now in the STATUS rather than in a timestamp beside it,
 * which is what lets a filter, a count and a dashboard tile each get it right
 * without re-deriving the rule. `revoked_at` stays on every representation: it
 * is when, and the status is what.
 */
class Invitation extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'hr_invitations';

    /** Filed where the invitation assigns the person (ChangeFeed). */
    public function changeFeedPlace(): array
    {
        return [$this->assigned_company_id, $this->assigned_franchise_id];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'People who were asked to join, whether or not they ever did. An invitation '
            .'nobody accepted still holds a real person\'s name and address — which is the '
            .'reason this table is listed at all, since nothing else in the system knows '
            .'that person exists.';
    }

    /**
     * Twenty-four months, and the number is here rather than in a comment.
     *
     * This table was the clearest case of unbounded growth in the system: a row
     * per person ever invited, kept forever, holding a real name and a real email
     * address for people who never became users and about whom nothing else in
     * the software knows anything. An organization that invites twenty people a
     * month and hires five accumulates a mailing list of the fifteen it did not.
     *
     * Two years, because the question this row answers — "we invited them and
     * changed our mind", "they were offered a job and never took it" — is a
     * question about a hiring round, and hiring rounds stop being disputed long
     * before two years. Accepted invitations age out on the same clock: once
     * somebody is a user, the account is the record of them joining and the
     * invitation is a duplicate of a fact stored better elsewhere.
     *
     * ── What this reaches that is NOT this table ─────────────────
     *
     * `onboardings.invitation_id` is `constrained('invitations')->nullOnDelete()`,
     * so the sweep does not only remove rows here — at twenty-four months it
     * nulls that column on an onboarding whose own retention is `forever`. That
     * is the designed behaviour and the migration argues for it: the alternative
     * cascade would let expiry take out a signed agreement, and a restrict would
     * make this window unenforceable the moment anybody was actually hired. What
     * it must not be is a surprise, so it is written into both sentences and
     * pinned by
     * RetentionTest::test_expiring_an_invitation_keeps_the_onboarding_it_produced.
     *
     * Nothing reads the link without allowing for its absence — every caller uses
     * `invitation?->`, and the scope helper checks for null — because an
     * onboarding started for somebody already employed never had one.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'created_at',
            'Kept after the invitation is spent, withdrawn or lapsed, because the row is how '
            .'"we invited them and changed our mind" stays answerable. Two years is long '
            .'enough for that question and short enough that an invitation nobody accepted '
            .'does not become a permanent record of a person who never joined. Where the '
            .'invitation led to a contract, the paperwork and the contract stay — they simply '
            .'stop naming the invitation that started them.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'Who the invitation is for, and the address the link is sent to.',
                // NOT NULL, and `.invalid` is unroutable by RFC 2606 — this
                // column is one that other code is entitled to send mail to.
                Erasure::erase('removed-{ref}@invalid')),
            new PersonalDataEntry('first_name, last_name', PersonalDataEntry::IDENTITY,
                'Pre-filled by the employer where they knew it, so the person does not '
                .'have to type it again.',
                Erasure::erase()),
            new PersonalDataEntry('token_hash', PersonalDataEntry::TECHNICAL,
                'The invitation link, stored as a sha256 so the credential itself is not '
                .'held. It is not reversible and is never sent anywhere.',
                Erasure::keep('A sha256 of a 48-character random string this system '
                    .'generated. It identifies the invitation, says nothing about the '
                    .'person, and is not reversible — and it is what the acceptance link '
                    .'is matched against, so blanking it on a live invitation would break '
                    .'a link somebody is holding rather than erase anything about them.')),
        ];
    }

    /**
     * Invitations addressed to this person.
     *
     * By `accepted_user_id` where they joined, and by EMAIL where they did not —
     * which is the whole reason this table is in the inventory. An invitation
     * nobody accepted is the only record that a particular person exists, so
     * matching only on the foreign key would leave exactly the rows that cannot
     * be found any other way.
     *
     * `invited_by_user_id` is not matched. An invitation the subject SENT is a
     * record about its recipient, and erasing the sender must not take somebody
     * else's row with it.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }

        // Grouped, not flat, so the Builder handed back stays this question when
        // a caller adds a predicate to it. See ContractSignature::personalDataAbout,
        // where the same brackets are argued at length — including why the tenant
        // scope is NOT what they defend against.
        return static::query()->where(fn (Builder $q) => $q
            ->where('accepted_user_id', $subject->getKey())
            ->orWhere('email', $subject->email));
    }

    /** The plan's six, and the only strings the API ever puts in `status`. */
    public const SENT        = 'sent';
    public const RECEIVED    = 'received';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const ABANDONED   = 'abandoned';
    public const REVOKED     = 'revoked';

    /** Mirrors Assignment's three scope kinds — the wire names for them (§4). */
    public const SCOPE_ORGANIZATION = Assignment::SCOPE_ORGANIZATION;
    public const SCOPE_COMPANY      = Assignment::SCOPE_COMPANY;
    public const SCOPE_FRANCHISE    = Assignment::SCOPE_FRANCHISE;

    /**
     * `token_hash` is deliberately NOT fillable.
     *
     * It is the credential. Everything that writes one goes through
     * App\Services\Hr\Invitations, which is the only place that has ever seen
     * the raw token — keeping the column unfillable means no request payload and
     * no future writer can set it by accident from something guessable.
     */
    protected $fillable = [
        'organization_id', 'email', 'first_name', 'last_name',
        'role_id', 'invited_by_user_id', 'invite_link_id',
        'assigned_organization_id', 'assigned_company_id', 'assigned_franchise_id',
        'sent_at', 'held_at', 'expires_at', 'opened_at', 'accepted_at', 'revoked_at',
        'accepted_user_id',
    ];

    /**
     * The hash never leaves the server, in any representation.
     *
     * The presenters below build their arrays by hand, so this is belt to that
     * brace — but a `toJson()` added anywhere later would otherwise put a live
     * credential's hash on the wire.
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'sent_at'     => 'datetime',
            'held_at'     => 'datetime',
            'expires_at'  => 'datetime',
            'opened_at'   => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    public static function booted(): void
    {
        // The application half of invitations_exactly_one_scope. Same reasoning
        // as Assignment::booted: the database is the real guarantee, this is
        // what makes the failure a sentence instead of SQL error 3819.
        static::saving(function (self $invitation) {
            $set = collect([
                $invitation->assigned_organization_id,
                $invitation->assigned_company_id,
                $invitation->assigned_franchise_id,
            ])->filter()->count();

            if ($set !== 1) {
                throw new InvalidArgumentException(
                    "An invitation must target exactly one scope; {$set} were set. "
                    .'It becomes an assignment row on acceptance, and §4 allows those '
                    .'exactly one level.'
                );
            }
        });
    }

    // ── relations ────────────────────────────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /**
     * The onboarding started against this invitation, if one has been.
     *
     * `hasOne` of the LIVE onboarding: cancelled starts do not occupy the
     * invitation. The schema no longer unique-indexes `invitation_id` so a
     * replacement can be started after a cancel; this relation is what
     * `status()` and the start endpoint still treat as "one arrival".
     *
     * Eager-load it wherever invitations are listed. `status()` reads it, so a
     * list that does not will lazy-load one query per row; correct either way,
     * and HrInvitationsController::index loads it for that reason.
     */
    public function onboarding(): HasOne
    {
        return $this->hasOne(Onboarding::class)->ofMany(
            ['started_at' => 'max'],
            fn (Builder $q) => $q->where('status', '!=', Onboarding::CANCELLED),
        );
    }

    public function assignedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_organization_id');
    }

    public function assignedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'assigned_company_id');
    }

    public function assignedFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'assigned_franchise_id');
    }

    // ── shape ────────────────────────────────────────────────────

    /** Which of §4's three levels this invitation assigns. */
    public function scopeType(): string
    {
        return match (true) {
            $this->assigned_franchise_id !== null    => self::SCOPE_FRANCHISE,
            $this->assigned_company_id !== null      => self::SCOPE_COMPANY,
            $this->assigned_organization_id !== null => self::SCOPE_ORGANIZATION,
            // Unreachable: the CHECK constraint and the saving hook both refuse it.
            default => throw new InvalidArgumentException('Invitation has no scope.'),
        };
    }

    public function scopeId(): string
    {
        return $this->assigned_franchise_id
            ?? $this->assigned_company_id
            ?? $this->assigned_organization_id;
    }

    /**
     * The same scope, in the two words the automation engine matches on.
     *
     * A separate method from `scopeType()`/`scopeId()` because it answers a
     * different question and gives a different answer to one of the three cases:
     * an invitation issued at ORGANIZATION level reports no scope here, which is
     * how `Automation::coversScope` is told "only an organization-wide rule
     * covers this". Returning `['organization', $id]` instead would be a
     * franchise-shaped answer for something that is not a franchise, and the
     * comparison it feeds only ever holds `company` or `franchise`.
     */
    public function automationScope(): array
    {
        return match ($this->scopeType()) {
            self::SCOPE_FRANCHISE    => ['franchise', $this->assigned_franchise_id],
            self::SCOPE_COMPANY      => ['company', $this->assigned_company_id],
            self::SCOPE_ORGANIZATION => [null, null],
        };
    }

    /** The columns an `assignments` row needs to reproduce this invitation's scope. */
    public function assignmentColumns(): array
    {
        return [
            'assigned_organization_id' => $this->assigned_organization_id,
            'assigned_company_id'      => $this->assigned_company_id,
            'assigned_franchise_id'    => $this->assigned_franchise_id,
        ];
    }

    /**
     * Is the place this invitation names still somewhere a person can be put?
     *
     * Asked at acceptance, a week after the invitation was written. Companies
     * and franchises are soft-deleted, so the foreign key's ON DELETE CASCADE
     * never fires for them — the row survives pointing at a `deleted_at` that
     * makes the relation answer null. And either can be switched off, which
     * leaves it present and not somewhere to place a new starter: they would get
     * an account whose only assignment is to a franchise nobody works at.
     *
     * The ORGANIZATION level asks about existence only, deliberately. An
     * organization that is switched off is a lapsed subscription (§13), and the
     * acceptance write already answers that with a 402 carrying the
     * subscription's own refusal. Restating it here would turn a payment problem
     * into "this invitation is no longer valid", which sends the wrong person to
     * fix the wrong thing.
     */
    public function scopeIsLive(): bool
    {
        return match ($this->scopeType()) {
            self::SCOPE_ORGANIZATION => $this->assignedOrganization !== null,
            self::SCOPE_COMPANY      => $this->assignedCompany?->is_active === true,
            self::SCOPE_FRANCHISE    => $this->assignedFranchise?->is_active === true,
        };
    }

    /** What the assigned place is CALLED — for the list, and for the audit trail. */
    public function scopeLabel(): ?string
    {
        return match ($this->scopeType()) {
            self::SCOPE_ORGANIZATION => $this->assignedOrganization?->name,
            self::SCOPE_COMPANY      => $this->assignedCompany?->name,
            self::SCOPE_FRANCHISE    => $this->assignedFranchise?->name,
        };
    }

    // ── lifecycle ────────────────────────────────────────────────

    public function hasExpired(): bool
    {
        // Held, it has not been sent, so its week has not started.
        return $this->held_at === null && $this->expires_at->isPast();
    }

    /** Waiting for the employer's signature before it is emailed. */
    public function isHeld(): bool
    {
        return $this->held_at !== null;
    }

    /**
     * Can this token still be used?
     *
     * The single question the public endpoints ask. Deliberately one method
     * rather than four checks at the call site: the four ways an invitation can
     * be dead must be indistinguishable to an unauthenticated caller, and a
     * caller that asked them separately would eventually answer them separately.
     */
    public function isOpen(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && ! $this->hasExpired();
    }

    /**
     * The plan's six, in the order that resolves them.
     *
     * Acceptance wins over everything: an invitation accepted on the sixth day
     * is `completed` forever, even when read a year later, because what happened
     * happened.
     *
     * Revocation is checked before expiry, and now that the two are different
     * words the order decides which one a withdrawn invitation reports once its
     * week has also run out. `revoked` is the right answer there: the
     * organization made a decision, and the clock running out afterwards did not
     * un-make it. Reversing these two would turn every withdrawal into an
     * expiry after seven days and quietly rewrite the history the row exists to
     * keep.
     */
    /**
     * status() as a query, in the same order, so the list can count and filter
     * by it across every page (HR lists, layout A, Mia 2026-10-02). `opened`
     * is received and in progress together: both are "they have the link open,
     * nothing is finished".
     */
    public function scopeInStatus(Builder $query, string $status): Builder
    {
        $pending = fn (Builder $q) => $q->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        return match ($status) {
            self::COMPLETED => $query->whereNotNull('accepted_at')->whereNull('revoked_at'),
            self::REVOKED => $query->whereNotNull('revoked_at'),
            self::ABANDONED => $query->whereNull('accepted_at')->whereNull('revoked_at')
                ->where('expires_at', '<=', now()),
            'opened' => $pending($query)->where(fn (Builder $q) => $q->whereNotNull('opened_at')
                ->orWhereHas('onboarding')),
            self::SENT => $pending($query)->whereNull('opened_at')->whereDoesntHave('onboarding'),
            default => $query,
        };
    }

    public function status(): string
    {
        return match (true) {
            // Revoked first: one may be withdrawn after it was accepted.
            $this->revoked_at !== null  => self::REVOKED,
            $this->accepted_at !== null => self::COMPLETED,
            $this->hasExpired()         => self::ABANDONED,
            $this->onboarding !== null  => self::IN_PROGRESS,
            $this->opened_at !== null   => self::RECEIVED,
            default                     => self::SENT,
        };
    }
}
