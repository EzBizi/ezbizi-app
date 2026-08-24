<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This role may use that number" — tenant-scoped, not a permissions row.
 */
class PhoneNumberGrant extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which roles may open which phone lines. Live access config: one row per '
            .'(number, role), deleted when the grant is revoked or either end goes away.'
        );
    }

    protected $fillable = ['organization_id', 'number_id', 'role_id'];

    public function number(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'number_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
