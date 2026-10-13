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
 * The words of one RingCentral call on the Experimental call report. See the
 * migration for where they come from.
 */
class CallReportTranscript extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const SOURCES = ['lead', 'whisper', 'failed'];

    protected $fillable = ['organization_id', 'call_id', 'transcript', 'transcript_labelled', 'source', 'answered_by', 'call_started_at'];

    protected function casts(): array
    {
        return ['call_started_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return 'What was said on a phone call, so the call report can show why a caller did or did not book.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'RingCentral keeps a year of calls and recordings; the words of a call are kept '
            .'no longer than the call they describe.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('transcript', PersonalDataEntry::CONTENT,
                'The words of a recorded call, transcribed on EzBizi\'s own server from '
                .'RingCentral\'s recording, or copied from the same call\'s Local Services lead. '
                .'The audio is fetched, transcribed and deleted; only the words are kept.',
                Erasure::erase()),
            new PersonalDataEntry('transcript_labelled', PersonalDataEntry::CONTENT,
                'The same words split by who said them (phone menu, dispatcher, caller), by Grok '
                .'from the transcript above.',
                Erasure::erase()),
            new PersonalDataEntry('answered_by', PersonalDataEntry::IDENTITY,
                'The name of the staff member who answered the call, as the report shows it, '
                .'so the score and review of a call belong to the person who took it.',
                Erasure::keep('a work record of who handled a business call; it goes with the row after a year')),
        ];
    }

    /**
     * A row is keyed by a RingCentral call, not a customer or a user, so
     * nothing here can be found from a person record. The year window is what
     * removes it.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
