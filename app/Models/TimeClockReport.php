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
 * A saved Work Hours report: who is in it and who receives it (2026-09-28).
 *
 * Sent by hand from Work Hours › Reports and by the `work_hours.send_report`
 * automation action — one definition, so the weekly email and the button
 * cannot drift apart.
 */
class TimeClockReport extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Which people a weekly hours report covers and which addresses it is sent to.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A configuration, kept until somebody deletes the report. It holds no hours '
            .'itself; the hours are read fresh from the shifts every time it is sent.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('include_user_ids, exclude_user_ids, created_by', PersonalDataEntry::IDENTITY,
                'People named in or out of the report, and who made it.',
                Erasure::keep('Account ids, anonymised at their one home. An erased person '
                    .'has no hours to report, so their id selects nothing.')),
            new PersonalDataEntry('recipients', PersonalDataEntry::CONTACT,
                'Email addresses the report is sent to.',
                Erasure::keep('Typed by an administrator as a delivery address for the '
                    .'business, not collected from the person; removed by editing the report.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()
                ->whereJsonContains('include_user_ids', $subject->getKey())
                ->orWhereJsonContains('exclude_user_ids', $subject->getKey())
                ->orWhere('created_by', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'organization_id', 'name', 'role_ids', 'include_user_ids',
        'exclude_user_ids', 'recipients', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'role_ids' => 'array',
            'include_user_ids' => 'array',
            'exclude_user_ids' => 'array',
            'recipients' => 'array',
        ];
    }
}
