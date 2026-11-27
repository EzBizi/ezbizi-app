<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note somebody left on a job (2026-10-20). Its author, or an
 * administrator, may change or remove it (Jose, 2026-11-17: "i cannot edit
 * notes"); a changed one says so (`edited_at`).
 */
#[Fillable(['organization_id', 'job_id', 'user_id', 'body', 'mentions', 'edited_at'])]
class JobNote extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $casts = ['mentions' => 'array', 'edited_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The author, or an administrator; either still needs to be able to edit the job. */
    public function changeableBy(?User $user): bool
    {
        return $user !== null && ($this->user_id === $user->getKey() || $user->isAdministrator());
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A note staff wrote on a job, and who wrote it. Deleted with the job.');
    }
}
