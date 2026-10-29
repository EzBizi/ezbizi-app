<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One bookable window — "10:00 to 13:00" — stated once for the whole
 * organization. Every service area uses the same windows; what differs per area
 * is how many jobs each takes, which lives on ServiceAreaSlot.
 *
 * See the migration for why the capacity rows copy the window rather than
 * pointing at it.
 */
#[Fillable(['organization_id', 'company_id', 'start_time', 'end_time'])]
class SchedulingTimeSlot extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The hours a business books into. Configuration, not a record of a day, '
            .'so it stays as long as the organization does.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    /**
     * Whose windows a company books into: its own, else the nearest parent
     * company's, else the organization's (null). Null company asks for the
     * organization's.
     */
    public static function ownerFor(?string $companyId): ?string
    {
        $seen = [];
        while ($companyId !== null && ! in_array($companyId, $seen, true)) {
            if (static::query()->where('company_id', $companyId)->exists()) {
                return $companyId;
            }
            $seen[] = $companyId;
            $companyId = Company::query()->whereKey($companyId)->value('parent_company_id');
        }

        return null;
    }

    /** @return list<string> companies whose windows are $owner's */
    public static function inheritors(?string $owner): array
    {
        return Company::query()->pluck('id')->filter(fn ($c) => static::ownerFor($c) === $owner)->values()->all();
    }
}
