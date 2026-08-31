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

/**
 * One graded run at a test.
 */
class TrainingAttempt extends Model implements HoldsPersonalData
{
    use HasUuids;
    use BelongsToTenant;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Which test somebody took, when, what they answered and whether they passed. '
            .'It is the record an organization relies on to show that the person doing the '
            .'work had been trained for it.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The record of who passed what, and when, is the whole reason this module '
            .'exists. An age-based window would delete the oldest pass first — the one a '
            .'later dispute or an insurer is most likely to ask about. The account itself '
            .'is anonymised at its one home when somebody exercises erasure.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which person sat this test, so the result can be shown to them and to '
                    .'whoever has to evidence that they were trained.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a result from the person '
                    .'it is about without removing anything about them.')),
            new PersonalDataEntry('answers, score, passed, taken_at', PersonalDataEntry::ACTIVITY,
                'What they answered, what they scored, and whether that was a pass.',
                Erasure::keep('The result is the record. Blanking it would leave a row '
                    .'saying somebody was tested without saying how it went, which is worse '
                    .'than a result against an already-anonymised account.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'user_id', 'test_id', 'answers', 'score', 'passed', 'taken_at', 'expires_on',
    ];

    protected $casts = [
        'answers' => 'array',
        'passed' => 'bool',
        'taken_at' => 'datetime',
        'expires_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TrainingTest::class, 'test_id');
    }
}
