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

/**
 * Which client a dispatcher answers for on the Experimental call report, set
 * by hand per person (Jose, 2026-10-05: "add tag to the dispatcher").
 */
class CallReportDispatcherTag extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const TAGS = ['new', 'existing'];

    protected $fillable = ['organization_id', 'person', 'tag'];

    public static function personalDataPurpose(): string
    {
        return 'Which kind of caller each dispatcher answers for, used to filter the Experimental call report.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A setting about how the team is organised. It is replaced, not accumulated, '
            .'and goes when somebody clears it or the person is erased.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('person', PersonalDataEntry::IDENTITY,
                'The dispatcher\'s name as the phone system shows it.',
                Erasure::drop('The row only describes that person; without the name it says nothing.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('person', $subject->name)
            : null;
    }
}
