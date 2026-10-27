<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One finished day of RingCentral's call log, kept in EzBizi so call reports
 * survive the integration being disconnected (Jose, 2026-10-27). Written by
 * RingCentralCallLog::storedDay; this model exists to state what it holds and
 * how long.
 */
class CallLogDay extends Model implements HoldsPersonalData
{
    protected $table = 'call_log_days';

    protected $fillable = ['day_key', 'calls'];

    public static function personalDataPurpose(): string
    {
        return 'A finished day of the RingCentral call log, so call reports keep their history '
            .'in EzBizi: who called which line, when, and who on staff took it.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Matches the 3-month window on EzBizi\'s own call history (PhoneCall).');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('calls', PersonalDataEntry::CONTACT,
                'Each call that day: the caller\'s number and caller-ID name, the line, the '
                .'time, and the staff member who answered.',
                Erasure::drop('A day is one blob of many callers; it goes whole with its '
                    .'3-month window rather than being edited per person.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        // A day is not keyed by any one person; the window removes it.
        return null;
    }
}
