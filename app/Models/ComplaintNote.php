<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A note somebody left while working a complaint (2026-10-20). */
#[Fillable(['organization_id', 'complaint_id', 'user_id', 'body', 'created_at'])]
class ComplaintNote extends Model implements StatesRetention
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
        return RetentionPolicy::withParent('A note staff wrote while handling a complaint, and who wrote it. Deleted with the complaint.');
    }
}
