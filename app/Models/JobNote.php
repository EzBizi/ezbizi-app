<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note somebody left on a job (2026-10-20). Append-only, as complaint notes are. */
#[Fillable(['organization_id', 'job_id', 'user_id', 'body'])]
class JobNote extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A note staff wrote on a job, and who wrote it. Deleted with the job.');
    }
}
