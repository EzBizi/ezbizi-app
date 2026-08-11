<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * An offer of an account, with the role and the place already decided (§4).
 *
 * ── Status is derived, never stored ──────────────────────────────
 *
 * The plan names five: sent · received · in progress · completed · abandoned.
 * Four of them are a question about the timestamps on this row, and "abandoned"
 * is mostly a question about the CLOCK — a week has passed and nobody came.
 *
 * Storing it would mean a scheduled job whose only purpose is to make a column
 * agree with `now()`. When such a job stops, every expired invitation goes on
 * reporting "sent", so the screen says a dead link is live and somebody chases
 * a person who cannot get in. Derivation cannot drift, so there is no sweep in
 * routes/console.php for this and none is needed.
 *
 * `in progress` is the exception, and it is stated here rather than quietly
 * omitted: it belongs to the onboarding form the two sides fill, which does not
 * exist yet, so nothing can produce it today. The constant is declared because
 * the client and the server have to agree on the whole vocabulary from the
 * start — learning a sixth word later is a release where the two disagree.
 *
 * ── Revoked collapses into abandoned, but the row still says why ─
 *
 * The owner settled on five statuses and "revoked" is not one of them, so a
 * withdrawn invitation reports `abandoned` like an expired one. `revoked_at` is
 * carried on every representation beside the status, because "we changed our
 * mind" and "they never opened it" are the same outcome and completely
 * different facts, and the screen has to be able to say which.
 */
class Invitation extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    /** The plan's five, and the only strings the API ever puts in `status`. */
    public const SENT        = 'sent';
    public const RECEIVED    = 'received';
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';
    public const ABANDONED   = 'abandoned';

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
        'role_id', 'invited_by_user_id',
        'assigned_organization_id', 'assigned_company_id', 'assigned_franchise_id',
        'sent_at', 'expires_at', 'opened_at', 'accepted_at', 'revoked_at',
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

    /** The columns an `assignments` row needs to reproduce this invitation's scope. */
    public function assignmentColumns(): array
    {
        return [
            'assigned_organization_id' => $this->assigned_organization_id,
            'assigned_company_id'      => $this->assigned_company_id,
            'assigned_franchise_id'    => $this->assigned_franchise_id,
        ];
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
        return $this->expires_at->isPast();
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
     * The plan's five, in the order that resolves them.
     *
     * Acceptance wins over everything: an invitation accepted on the sixth day
     * is `completed` forever, even when read a year later, because what happened
     * happened. Revocation is checked before expiry so the row is not reported
     * as merely stale when somebody actually withdrew it — the status is the same
     * word either way, but the order is what keeps `revoked_at` meaningful to
     * anything that reads this alongside it.
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::COMPLETED,
            $this->revoked_at !== null  => self::ABANDONED,
            $this->hasExpired()         => self::ABANDONED,
            $this->opened_at !== null   => self::RECEIVED,
            default                     => self::SENT,
        };
    }
}
