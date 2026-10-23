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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One Tech Assistant answer, on WhatsApp or in the app, kept so it can be marked correct or corrected. */
class AssistantAnswer extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const REVIEWS = ['correct', 'incorrect', 'needs_review'];

    protected $table = 'assistant_answers';

    protected $fillable = [
        'user_id', 'job_id', 'channel', 'question', 'answer', 'order_check', 'confidence', 'part_ids', 'manual_pages',
        'trace_id', 'review', 'reviewed_by', 'reviewed_at', 'correction', 'correction_entry_id',
    ];

    protected function casts(): array
    {
        return ['part_ids' => 'array', 'manual_pages' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(AssistantJob::class, 'job_id');
    }

    public static function personalDataPurpose(): string
    {
        return 'What a technician asked the assistant and what it answered, so a wrong answer can be corrected.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(12, 'created_at', 'A technician\'s question and the assistant\'s answer, kept a year for review.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Which technician asked the assistant this question.', Erasure::drop('An old answer; dropping it loses only the review record.')),
            new PersonalDataEntry('question', PersonalDataEntry::CONTENT,
                'The technician\'s own words, which can name a customer.', Erasure::drop('An old answer; dropping it loses only the review record.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
