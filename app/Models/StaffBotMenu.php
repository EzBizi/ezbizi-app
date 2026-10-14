<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One menu of the staff line, for the roles it names (UI queue 88).
 */
class StaffBotMenu extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** WhatsApp's list holds 10 rows, and one is the bot's own Find a location. */
    public const MAX_ITEMS = 9;

    protected $fillable = ['organization_id', 'name', 'role_ids', 'position'];

    protected function casts(): array
    {
        return ['role_ids' => 'array', 'position' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StaffBotItem::class, 'menu_id')->orderBy('position');
    }

    /** Whether a person of this role gets this menu. */
    public function isFor(?string $roleId): bool
    {
        return $roleId !== null && in_array($roleId, $this->role_ids ?? [], true);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which menu the staff WhatsApp line shows each role, and its order. Configuration, '
            .'kept until the business deletes the menu.'
        );
    }
}
