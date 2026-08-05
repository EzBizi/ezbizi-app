<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One grantable action, e.g. `jobs.create`.
 *
 * A PLATFORM table, not tenant data — hence no BelongsToTenant and no
 * organization_id. The set of things the software can do is defined by the
 * software (plan §2.1: platform invariants stay in code). Clients compose these
 * into their own roles; they do not invent new actions, because an action with
 * no code behind it enforces nothing.
 *
 * Permissions are stored at ACTION level even though the UI shows a single
 * module toggle by default (§4). Storing module-level and adding actions later
 * would mean migrating every role in every organization.
 */
class Permission extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = ['key', 'module', 'action', 'label'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** Build the canonical key: module + action → `jobs.create`. */
    public static function key(string $module, string $action): string
    {
        return "{$module}.{$action}";
    }
}
