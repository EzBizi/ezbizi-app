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
 * AI's assessment of how a dispatcher handled one call, for coaching (Jose,
 * 2026-10-12, AI item 8). Keyed by call; who answered comes from the call log
 * in the Performance report, as the labels do.
 */
class CallReportScore extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'call_id', 'score', 'checks', 'strength', 'improve'];

    protected function casts(): array
    {
        return ['checks' => 'array', 'score' => 'integer'];
    }

    public static function personalDataPurpose(): string
    {
        return 'How a staff member handled a call, assessed by AI, so managers can coach them.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at',
            'An AI coaching note on how a call was handled, kept as long as the call\'s transcript is.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('strength', PersonalDataEntry::CONTENT,
                'One sentence by AI on what the staff member did well on the call, written from its transcript.',
                Erasure::erase()),
            new PersonalDataEntry('improve', PersonalDataEntry::CONTENT,
                'One sentence by AI on what the staff member could have done better on the call.',
                Erasure::erase()),
        ];
    }

    /** Keyed by a RingCentral call, as its transcript is; the year window removes it. */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
