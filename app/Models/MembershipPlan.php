<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A plan a customer can join: its name, price, and whether billed yearly or monthly. */
#[Fillable(['name', 'description', 'price_minor', 'currency', 'billing', 'job_type', 'is_active'])]
class MembershipPlan extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): organization-wide. */
    protected static string $changeType = 'membership_plans';

    use HasUuids;

    public const BILLINGS = ['yearly', 'monthly'];

    // ponytail: the old key, read by the 10-05 plan editor; drop with it.
    protected $appends = ['workiz_job_type'];

    public function getWorkizJobTypeAttribute(): ?string
    {
        return $this->job_type;
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A plan the organization offers: a name and a price, nothing about any person. '
            .'Removing the organization takes it.'
        );
    }

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'is_active' => 'boolean'];
    }
}
