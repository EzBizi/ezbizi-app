<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Modules\PermissionCatalogue;
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
 *
 * There are no preset roles beyond Administrator — the owner's decision. Every
 * organization composes its own from the permission catalogue the modules
 * declare (see PermissionCatalogue).
 */
class Role extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    /**
     * The name the built-in role is CREATED with — not what identifies it.
     *
     * Identity is `is_administrator`. This constant survives only as the default
     * display name at provisioning time; nothing may branch on it. See the
     * 2026_08_10_010000 migration for what branching on it cost.
     */
    public const ADMINISTRATOR = 'Administrator';

    /**
     * `is_administrator` is deliberately NOT fillable.
     *
     * It is the one attribute that grants everything, so it must be unreachable
     * from any request payload. ProvisionOrganization sets it explicitly with
     * forceFill; every other writer — including the roles editor — physically
     * cannot, which is a stronger guarantee than a policy check that somebody
     * later forgets to add to a second endpoint.
     */
    protected $fillable = ['organization_id', 'name', 'is_system'];

    protected function casts(): array
    {
        return [
            'is_system'        => 'boolean',
            'is_administrator' => 'boolean',
            // The roles this role's holders may offer, or null for the rule
            // by reach (PermissionCatalogue::mayAssignRole).
            'invitable_role_ids' => 'array',
            // Its holders are never named as viewing a lead (LeadPresence).
            'hidden_on_leads' => 'boolean',
            // Phone bottom-bar slots, [{module, tab}] or null (MobileBar).
            'mobile_bar' => 'array',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The organization's own top role. Created with the org; cannot be edited or deleted. */
    public function isAdministrator(): bool
    {
        return (bool) $this->is_administrator;
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

    /** The keys this role actually holds. Administrator holds none of its own — see hasPermission. */
    public function permissionKeys(): array
    {
        return $this->permissions()->pluck('key')->sort()->values()->all();
    }

    /**
     * Replace this role's permissions, and report what moved.
     *
     * ── Why the invariant lives HERE and not in the controller ───
     *
     * §4 presents a module as ONE switch with an expandable detail, and the
     * reason it is one switch rather than two is that "may edit, tab hidden" and
     * "sees the tab, may view nothing" must be unrepresentable. A rule enforced
     * only where the editor posts is a rule that the next write path — an
     * importer, an HR bulk action, a seeder — will not inherit. So every write
     * of a role's permissions goes through here, and here it is normalised:
     * granting any action of a module implies that module's view.
     *
     * Returns the diff rather than nothing, because the audit entry somebody
     * will actually need is "who added delete to Dispatchers", and a record that
     * says only "permissions changed" cannot answer it.
     *
     * @param  string[]  $keys
     * @return array{added: string[], removed: string[]}
     */
    public function syncPermissionKeys(array $keys): array
    {
        $catalogue = app(PermissionCatalogue::class);

        $before = $this->permissionKeys();
        $after  = $catalogue->withImpliedView($keys);

        $added   = array_values(array_diff($after, $before));
        $removed = array_values(array_diff($before, $after));

        if ($added === [] && $removed === []) {
            return ['added' => [], 'removed' => []];
        }

        $this->permissions()->sync(
            array_map(fn (string $key) => $catalogue->materialise($key)->getKey(), $after)
        );

        $this->unsetRelation('permissions');

        return ['added' => $added, 'removed' => $removed];
    }
}
