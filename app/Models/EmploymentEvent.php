<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmploymentEvent extends Model
{
    use HasUuids;
    use BelongsToTenant;

    public const STARTED = 'started';

    public const LEFT = 'left';

    protected $fillable = [
        'organization_id', 'user_id', 'kind', 'occurred_on',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
