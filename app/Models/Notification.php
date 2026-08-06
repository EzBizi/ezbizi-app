<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing somebody should know about (plan §12).
 *
 * Addressed to a USER, not a role or a franchise. Roles change and assignments
 * change; a notification is about a moment, and re-deriving its audience later
 * would silently change who had been told what.
 */
class Notification extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const UPDATED_AT = null;   // read_at is the only mutation

    protected $fillable = [
        'organization_id', 'user_id', 'source', 'title', 'body',
        'subject_type', 'subject_id', 'workflow_id', 'event_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
