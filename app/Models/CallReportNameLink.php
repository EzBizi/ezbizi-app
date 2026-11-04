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
 * A phone spelling that is one Workiz person (PersonNames; Jose, 2026-11-04:
 * names change without a release).
 */
class CallReportNameLink extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'phone_name', 'name'];

    public static function personalDataPurpose(): string
    {
        return 'Which phone-system name and which Workiz name are the same member of staff, so the call reports count them once.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A setting about who is who on the team. It is replaced, not accumulated, '
            .'and goes when somebody clears it or the person is erased.'
        );
    }

    public static function personalDataHeld(): array
    {
        $drop = Erasure::drop('The row only says two names are one person; without them it says nothing.');

        return [
            new PersonalDataEntry('phone_name', PersonalDataEntry::IDENTITY, 'The person\'s name as the phone system shows it.', $drop),
            new PersonalDataEntry('name', PersonalDataEntry::IDENTITY, 'The person\'s name as Workiz shows it.', $drop),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where(fn ($q) => $q->where('phone_name', $subject->name)->orWhere('name', $subject->name))
            : null;
    }
}
