<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "View as" (Jose, 2026-11-23): an Administrator who is platform staff
 * seeing the app as a role at a place, or as a person. See App\Support\ViewAs.
 */
#[Fillable([
    'organization_id', 'actor_id', 'personal_access_token_id', 'target_user_id',
    'role_id', 'scope_type', 'scope_id', 'expires_at', 'ended_at',
])]
class ViewAsSession extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const MINUTES = 60;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Which developer viewed the app as which role or person, on which sign-in. '
            .'Deleted with the sign-in token it was started on; the audit log keeps the record.'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id')->withoutGlobalScopes();
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes();
    }

    /** Not ended and not expired. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    public function kind(): string
    {
        return $this->target_user_id !== null ? 'person' : 'role';
    }
}
