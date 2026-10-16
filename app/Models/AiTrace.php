<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One Grok call: what was asked, what came back, how long it took (GrokCli). */
class AiTrace extends Model implements HoldsPersonalData
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'scope', 'subject_type', 'subject_id', 'prompt', 'schema', 'response', 'error', 'ms'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'response' => 'array'];
    }

    public static function personalDataPurpose(): string
    {
        return 'What was sent to the AI and what it answered, so a wrong answer can be checked and the prompt fixed.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(1, 'created_at', 'A record of one AI call, kept a month for checking answers.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('prompt', PersonalDataEntry::CONTENT,
                'The text sent to the AI, which can include a call transcript or a staff question.', Erasure::drop('A month-old trace; dropping it loses only the debugging record.')),
            new PersonalDataEntry('response', PersonalDataEntry::CONTENT,
                'What the AI answered.', Erasure::drop('As the prompt.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        // Set where the caller knows whose data it sent (GrokCli::about: a
        // lead, the asking user). Calls keyed by a RingCentral call carry no
        // model, as their transcripts do not; the month window removes those.
        return static::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', (string) $subject->getKey());
    }
}
