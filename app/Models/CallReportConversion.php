<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A call somebody marked as booked by hand on the Experimental report ("Convert
 * to booked", Jose, 2026-10-05). The rows ARE the not-booked-to-booked history.
 */
class CallReportConversion extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'call_id', 'call_started_at', 'converted_by'];

    protected function casts(): array
    {
        return ['call_started_at' => 'datetime'];
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which member of staff marked a phone call as booked on the Experimental call report, and when.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'created_at',
            'The booking history behind the call report. RingCentral keeps a year of calls, '
            .'so a mark older than two years points at a call nobody can open.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('converted_by', PersonalDataEntry::ACTIVITY,
                'Which member of staff marked the call as booked.',
                Erasure::erase()),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('converted_by', $subject->getKey())
            : null;
    }
}
