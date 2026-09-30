<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One person has seen one lead, as of a moment.
 *
 * Two ids and a timestamp, written and read by LeadsController through the
 * query builder. The class exists for the privacy contracts: a table with a
 * user in it is personal data, and a table with no model is invisible to the
 * §9 inventory and to the purge - not exempt from them. The deploy desk's
 * gate said so on 2026-09-08, in the retention test's own words, and it was
 * right; my "no model on purpose" in the migration was wrong about what a
 * model is for here.
 *
 * Composite key (lead_id, user_id), no id column, no timestamps beyond
 * read_at. Never load rows through this class to delete them one by one:
 * Eloquent cannot address a composite key, so deletes go through the query
 * builder, which is what the purge does.
 */
class LeadRead extends Model implements HoldsPersonalData, StatesRetention
{
    protected $table = 'lead_reads';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['lead_id', 'user_id', 'read_at', 'starred_at'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Whether a particular person has opened a particular enquiry. Held for as long as '
            .'both exist: it goes with the lead when the lead goes, and with the person when '
            .'they are removed. Shown only as "unread" to that person; nobody else sees whose '
            .'mark it is.'
        );
    }

    public static function personalDataPurpose(): string
    {
        return 'So each dispatcher\'s Unread filter is theirs: what one person has opened is '
            .'not what another has.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id, read_at, starred_at', PersonalDataEntry::ACTIVITY,
                'Who opened the enquiry and when, and whether they starred it.',
                Erasure::drop('A read mark belongs to exactly one person and says nothing '
                    .'without them. The column is half the primary key, so it cannot be '
                    .'blanked; the row goes.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'starred_at' => 'datetime'];
    }
}
