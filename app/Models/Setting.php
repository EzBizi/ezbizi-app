<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One setting override at one scope (plan §6).
 *
 * Read and written through SettingsResolver, never directly — a bare read of
 * this table gives you whatever that scope happens to have set, which is not the
 * effective value. The whole point of the cascade is that absence means inherit.
 */
class Setting extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    protected $fillable = ['organization_id', 'scope_type', 'scope_id', 'key', 'value'];

    protected function casts(): array
    {
        // Stored as JSON so one column carries bool/int/string/enum. The
        // SettingDefinition owns the real type and casts on read.
        return ['value' => 'json'];
    }
}
