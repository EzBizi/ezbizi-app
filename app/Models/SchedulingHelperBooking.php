<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A helper booked to a technician for a day or half (full | am | pm). */
#[Fillable(['organization_id', 'franchise_id', 'helper_id', 'date', 'slot', 'technician_id', 'job_number', 'note', 'created_by', 'updated_by'])]
class SchedulingHelperBooking extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const SLOTS = ['full', 'am', 'pm'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** The slots a wanted one collides with: full takes the whole day. */
    public static function collides(string $wanted): array
    {
        return match ($wanted) {
            'full' => ['full', 'am', 'pm'],
            'am' => ['full', 'am'],
            'pm' => ['full', 'pm'],
            default => [],
        };
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'date',
            'Who worked with whom on which day: kept for two years for payroll and disputes.');
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(SchedulingHelper::class, 'helper_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
