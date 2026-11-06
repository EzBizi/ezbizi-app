<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A promotion text sent to the customers of Done jobs (Jose, 2026-11-06). */
#[Fillable(['organization_id', 'jobs_from', 'jobs_to', 'body', 'franchise_ids', 'recipients', 'sent', 'failed', 'sent_by', 'finished_at'])]
class Promotion extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['jobs_from' => 'date:Y-m-d', 'jobs_to' => 'date:Y-m-d', 'franchise_ids' => 'array', 'finished_at' => 'datetime'];
    }

    public function texts(): HasMany
    {
        return $this->hasMany(PromotionText::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'created_at',
            'What promotion went out, when and to how many: kept two years, the record of marketing a company sent.');
    }
}
