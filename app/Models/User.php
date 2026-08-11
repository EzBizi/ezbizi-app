<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTotp;
use App\Support\Modules\ModuleAvailability;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A user belongs to exactly ONE organization (plan §4).
 *
 * Somebody working for two clients holds two separate accounts — the owner's
 * decision, and it keeps the tenant boundary absolute: there is no object in the
 * system that spans organizations, so there is nothing to leak across one.
 *
 * Consequence: email is unique PER ORGANIZATION, not globally, so the same
 * address can exist in two organizations. Login must therefore disambiguate
 * when an address matches more than one account.
 */
#[Fillable(['organization_id', 'role_id', 'name', 'email', 'password', 'is_active',
    'first_name', 'middle_name', 'last_name', 'phone', 'gender', 'date_of_birth',
])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'pending_totp_secret', 'recovery_codes'])]
class User extends Authenticatable implements HoldsPersonalData
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use Concerns\ReachesCompanies;
    use HasTotp;
    use HasApiTokens;
    use Notifiable;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The people who work at this organization. Every employee is an account, '
            .'so this is where their name, contact details and second factor live — and the '
            .'HR plan\'s one-home rule means it is the ONLY place they live: HR points here '
            .'rather than keeping a second copy.';
    }

    public static function personalDataRetention(): string
    {
        return 'For as long as the account exists. Somebody who leaves is deactivated rather '
            .'than deleted, because everything they did stays attributed to them (§5) — so '
            .'the record of a former employee is kept deliberately, and erasing one means '
            .'anonymising it rather than removing the row.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name, first_name, middle_name, last_name',
                PersonalDataEntry::IDENTITY,
                'Who somebody is, and the name shown against everything they do.'),
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'The identity they sign in with, and where a password reset goes.'),
            new PersonalDataEntry('phone', PersonalDataEntry::CONTACT,
                'Reaching a colleague about work in progress.'),
            new PersonalDataEntry('date_of_birth', PersonalDataEntry::IDENTITY,
                'Asked at onboarding because contracts and payroll need it. Deliberately '
                .'excluded from the audit log — §9 gives it a legal weight that an '
                .'append-only table erasure cannot reach is the wrong home for.'),
            new PersonalDataEntry('gender', PersonalDataEntry::IDENTITY,
                'Optional, and free text rather than a closed list. Held only where '
                .'somebody chose to give it.'),
            new PersonalDataEntry('avatar_path', PersonalDataEntry::IDENTITY,
                'A photograph of the person, served only to their own organization.'),
            new PersonalDataEntry('totp_secret, pending_totp_secret, recovery_codes',
                PersonalDataEntry::TECHNICAL,
                'The second factor, where the account has one.',
                encrypted: true),
        ];
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'totp_confirmed_at' => 'datetime',
            // Encrypted at rest: a leak that exposes TOTP secrets makes the
            // second factor worthless, and recovery codes are a full bypass.
            'totp_secret'         => 'encrypted',
            'pending_totp_secret' => 'encrypted',
            'recovery_codes'      => 'encrypted:array',
            'last_totp_timestamp' => 'integer',
            // §2.1 category 6 — theirs, nobody else's.
            'preferences'         => 'array',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    // ── permissions (§4) ─────────────────────────────────────────

    /**
     * WHAT this user may do. Separate from WHERE — see canActOnFranchise().
     * Both must pass; a permission without reach is meaningless, and reach
     * without permission is worse.
     *
     * Two gates, in order:
     *
     *   1. Is the module switched on at platform level? (§2.1 layer 3)
     *   2. Does this user's role grant the action?      (§4)
     *
     * The platform gate runs FIRST and applies to Administrators too. A kill
     * switch that exempts admins isn't a kill switch — during an incident the
     * admins are exactly the people still hammering the broken module.
     */
    public function hasPermission(string $key): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! app(ModuleAvailability::class)->isPermissionAvailable($key, $this->organization)) {
            return false;
        }

        return $this->role?->hasPermission($key) ?? false;
    }

    // ── authority (§4) ───────────────────────────────────────────

    /**
     * WHERE this user may act.
     *
     * Assignment does not cascade, but authority does: a company-level user may
     * act on everything beneath that company, including its Sub Companies'
     * franchises. Resolved as "is the target inside the subtree of any of my
     * assignments" — not an exact scope match, which would leave company managers
     * able to see work they cannot touch.
     */
    public function canActOnFranchise(Franchise $franchise): bool
    {
        if (! $this->is_active || $franchise->organization_id !== $this->organization_id) {
            return false;
        }

        foreach ($this->assignments as $assignment) {
            $reachable = match ($assignment->scopeType()) {
                // Organization-level: the whole tenant.
                Assignment::SCOPE_ORGANIZATION =>
                    $assignment->assigned_organization_id === $franchise->organization_id,

                // Company-level: this company's franchises, plus those of its
                // Sub Companies. The Sub Company step is easy to miss and would
                // silently hide half a company's work from its own manager.
                Assignment::SCOPE_COMPANY =>
                    in_array(
                        $franchise->company_id,
                        $assignment->assignedCompany?->subtreeCompanyIds() ?? [],
                        strict: true
                    ),

                // Franchise-level: exactly this one.
                Assignment::SCOPE_FRANCHISE =>
                    $assignment->assigned_franchise_id === $franchise->getKey(),
            };

            if ($reachable) {
                return true;
            }
        }

        return false;
    }

    /**
     * The top tier of the same question — the one that had no method.
     *
     * `canActOnFranchise` and `canActOnCompany` have existed since there was
     * something to act on at those levels. Nothing had ever acted on the
     * ORGANIZATION as a target, so the third case was never written; the first
     * thing that needs it is assigning somebody organization-wide, where getting
     * it wrong means a company manager handing out reach over companies they
     * cannot see themselves.
     *
     * Authority cascades downwards and never up (§4), so the organization is the
     * one level that no company or franchise assignment can reach: the answer is
     * simply whether this person holds an organization-scoped assignment.
     */
    public function canActOnOrganization(Organization $organization): bool
    {
        if (! $this->is_active || $organization->getKey() !== $this->organization_id) {
            return false;
        }

        return $this->assignments->contains(
            fn (Assignment $assignment) => $assignment->scopeType() === Assignment::SCOPE_ORGANIZATION
        );
    }

    /** Every franchise this user may act on, across all their assignments. */
    public function reachableFranchises(): \Illuminate\Support\Collection
    {
        return Franchise::query()
            ->where('organization_id', $this->organization_id)
            ->get()
            ->filter(fn (Franchise $f) => $this->canActOnFranchise($f))
            ->values();
    }

    public function isAdministrator(): bool
    {
        return $this->role?->isAdministrator() ?? false;
    }
}
