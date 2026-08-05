<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant root — the client (plan §3).
 *
 * Deliberately does NOT use BelongsToTenant: it *is* the tenant. Scoping it by
 * organization_id would be circular.
 */
class Organization extends Model
{
    use HasFactory;
    use HasUuids;      // Laravel 13: UUIDv7 — verified, time-ordered (§5)
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'reporting_currency', 'franchise_label',
        'shares_customers', 'trial_ends_at', 'is_active', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'shares_customers' => 'boolean',
            'is_active'        => 'boolean',
            'is_demo'          => 'boolean',
            'trial_ends_at'    => 'datetime',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    /** Top-level companies only. Sub Companies hang off their parent (§3). */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class)->whereNull('parent_company_id');
    }

    /** Every company in the tree, top-level and sub. */
    public function allCompanies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function franchises(): HasMany
    {
        return $this->hasMany(Franchise::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    // ── subscription (§13) ───────────────────────────────────────

    /**
     * Read-only when the trial has lapsed: the owner may still log in and view
     * their data, but cannot enter new data. Enforced server-side on every write
     * path — not by hiding buttons.
     */
    public function isReadOnly(): bool
    {
        if (! $this->is_active) {
            return true;
        }

        return $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }
}
