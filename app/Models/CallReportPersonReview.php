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

/** Grok's daily or weekly coaching read of one employee's calls (ezbizi:ai-person-reviews). */
class CallReportPersonReview extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'person', 'period', 'from', 'to', 'calls', 'avg_score', 'summary', 'strengths', 'work_on', 'trend'];

    protected function casts(): array
    {
        return ['strengths' => 'array', 'work_on' => 'array', 'from' => 'date', 'to' => 'date', 'avg_score' => 'float'];
    }

    public static function personalDataPurpose(): string
    {
        return 'An AI summary of how a staff member handled their calls over a day or week, so managers can coach them.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'A coaching summary of a staff member\'s calls, kept as long as the call scores it was written from.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('person', PersonalDataEntry::IDENTITY,
                'The staff member\'s name as the call report shows it.',
                Erasure::drop('The review exists only to describe that person.')),
            new PersonalDataEntry('summary, strengths, work_on', PersonalDataEntry::CONTENT,
                'AI\'s written assessment of the staff member\'s calls: a summary, strengths and things to work on.',
                Erasure::drop('The review exists only to describe that person.')),
        ];
    }

    /** Keyed by a name from the phone system, not a user record; the year window removes it. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
