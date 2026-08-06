<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTotp;
use App\Support\Modules\ModuleAvailability;
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
#[Fillable(['organization_id', 'role_id', 'name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token', 'totp_secret', 'pending_totp_secret', 'recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use HasTotp;
    use HasApiTokens;
    use Notifiable;

    protected function casts(): array
    {
        return [
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
