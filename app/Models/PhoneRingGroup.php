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
 * Who rings, and how, when a number is answered by a group.
 */
class PhoneRingGroup extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Ring groups for a connection. Live dial-plan config, removed when deleted or '
            .'when the connection goes.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'name', 'mode', 'timeout_seconds',
        'member_user_ids', 'member_role_ids',
        'overflow', 'overflow_group_id', 'overflow_number',
    ];

    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'member_user_ids' => 'array',
            'member_role_ids' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
