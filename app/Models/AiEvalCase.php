<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A traced AI call a person pinned with the right answer; ezbizi:ai-eval replays it. */
class AiEvalCase extends Model implements HoldsPersonalData
{
    use HasUuids;

    protected $fillable = ['organization_id', 'scope', 'prompt', 'schema', 'expected', 'notes', 'trace_id'];

    protected function casts(): array
    {
        return ['schema' => 'array', 'expected' => 'array'];
    }

    public static function personalDataPurpose(): string
    {
        return 'A sample AI question with its checked answer, used to test the AI after a prompt changes.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at', 'A test case, kept a year; pin a fresh one to keep testing.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('prompt', PersonalDataEntry::CONTENT,
                'The text sent to the AI, which can include a call transcript.', Erasure::drop('Dropping the case only loses one test.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }
}
