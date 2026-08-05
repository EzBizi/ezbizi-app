<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A role, defined once per organization (plan §4).
 *
 * Roles are organization-GLOBAL: a user's role does not vary per assignment.
 * Someone assigned to two franchises holds the same role in both; only their
 * scope differs. That was an explicit owner decision, and it keeps the
 * permission check to "what may this role do?" plus "where may this user act?"
 * rather than a matrix of the two.
 */
class Role extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const ADMINISTRATOR = 'Administrator';

    protected $fillable = ['organization_id', 'name', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The organization's own top role. Created with the org; cannot be deleted. */
    public function isAdministrator(): bool
    {
        return $this->is_system && $this->name === self::ADMINISTRATOR;
    }

    public function hasPermission(string $key): bool
    {
        // Administrator holds everything, including permissions added by future
        // modules — so a new module is never invisible to the person who owns
        // the organization (§5.5: every module registers its own permissions).
        if ($this->isAdministrator()) {
            return true;
        }

        return $this->permissions()->where('key', $key)->exists();
    }
}
