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
 * An inbound call-flow graph (nodes JSON) on a connection.
 */
class PhoneFlow extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Named call flows for a connection. Live dial-plan config, removed when deleted '
            .'or when the connection goes.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'name', 'nodes',
    ];

    protected function casts(): array
    {
        return [
            'nodes' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
