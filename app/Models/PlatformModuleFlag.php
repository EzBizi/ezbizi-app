<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One module's platform-wide availability (plan §2.1, layer 3).
 *
 * Platform data, not tenant data — no BelongsToTenant, no organization_id.
 * EzBizi staff own this; organizations cannot see or change it.
 */
class PlatformModuleFlag extends Model
{
    use HasFactory;
    use HasUuids;

    public const ENABLED  = 'enabled';
    public const BETA     = 'beta';
    public const DISABLED = 'disabled';

    protected $fillable = [
        'module_key', 'state', 'beta_organization_ids', 'reason', 'changed_by',
    ];

    protected function casts(): array
    {
        return ['beta_organization_ids' => 'array'];
    }

    /**
     * Is this module available to the given organization?
     *
     * Note `disabled` beats everything, including Administrator. A kill switch
     * that exempts admins isn't a kill switch — during an incident the admins
     * are precisely the people still hammering the broken module.
     */
    public function availableTo(?Organization $organization): bool
    {
        return match ($this->state) {
            self::DISABLED => false,
            self::ENABLED  => true,
            self::BETA     => $organization !== null
                && in_array($organization->getKey(), $this->beta_organization_ids ?? [], strict: true),
            default        => false,   // unknown state fails closed
        };
    }
}
