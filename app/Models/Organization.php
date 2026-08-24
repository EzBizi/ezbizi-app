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
        'name', 'slug', 'address', 'address_line1', 'address_line2', 'city',
        'state', 'postal_code', 'reporting_currency', 'franchise_label',
        'trial_ends_at', 'is_active', 'is_demo', 'logo_path', 'logo_aspect',
    ];

    protected function casts(): array
    {
        return [
            'is_active'     => 'boolean',
            'is_demo'       => 'boolean',
            'trial_ends_at' => 'datetime',
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

    /**
     * The one line a contract prints as {{organization.address}}.
     *
     * Structured fields are the source. `address` is the formatted snapshot so
     * merge tags and older callers keep reading one string.
     */
    public function formattedAddress(): ?string
    {
        $cityLine = collect([$this->city, $this->state, $this->postal_code])
            ->map(fn ($part) => is_string($part) ? trim($part) : null)
            ->filter()
            ->implode(' ');

        $parts = collect([
            is_string($this->address_line1) ? trim($this->address_line1) : null,
            is_string($this->address_line2) ? trim($this->address_line2) : null,
            $cityLine !== '' ? $cityLine : null,
        ])->filter();

        if ($parts->isNotEmpty()) {
            return $parts->implode(', ');
        }

        $legacy = is_string($this->address) ? trim($this->address) : '';

        return $legacy === '' ? null : $legacy;
    }
}
