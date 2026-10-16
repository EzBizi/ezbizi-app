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

/** New or existing client for one inbound call, and why. See the migration. */
class CallTypeCorrection extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const NEW = 'new';

    public const EXISTING = 'existing';

    protected $fillable = ['organization_id', 'call_id', 'pressed_queue', 'corrected_type', 'reason', 'evidence', 'confidence', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime', 'confidence' => 'integer'];
    }

    public static function personalDataPurpose(): string
    {
        return 'Whether a caller was a new or an existing client, so call reports count them right.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'Kept as long as the call it describes: RingCentral keeps a year of calls.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('reason', PersonalDataEntry::CONTENT,
                'Why the caller was judged new or existing, e.g. a prior job, or a short quote from the call.',
                Erasure::erase()),
            new PersonalDataEntry('decided_by', PersonalDataEntry::ACTIVITY,
                'The member of staff who overrode the judgement, when one did.',
                Erasure::keep('a work record of a decision on a business call; it goes with the row after a year')),
        ];
    }

    /** Keyed by a call, not a person; the year window removes it. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
