<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
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
class TrainingAttempt extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A test taken is its test's change on the live feed (the Dashboard counts move). */
    protected static array $changeParent = ['training', TrainingTest::class, 'test_id'];


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
                    .'whoever has to show they were trained.',
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
        // Not fillable — see ExpireTrainingPasses::announceWithinTenant(),
        // the only writer. It is an operational marker ("did our own sweep
        // already raise training.expired for this row"), not a fact about
        // the person, so it is deliberately absent from personalDataHeld()
        // below: it is not in the person-shaped-column list DataInventoryTest
        // checks against, and it answers no §9 "what do you hold about me"
        // question that `taken_at`/`expires_on` do not already answer.
        'expiry_announced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TrainingTest::class, 'test_id');
    }

    /**
     * Where this attempt belongs, for §4 reach and for `NotificationDispatcher`
     * — the LEARNER'S place, not the test's. Copied from
     * `TrainingProgress::automationScope()` exactly; that shape was reviewed
     * and is the settled one for this module — see its own docblock for the
     * full reasoning (the org-wide-guide case that motivates resolving from
     * the person's own assignment rather than the record's).
     *
     * Not optional: `training_attempts` carries no `franchise_id`/`company_id`
     * of its own (see the migration), so without this every `training.passed`
     * / `training.failed` event would arrive at `NotificationDispatcher`
     * claiming no scope — which `audienceFor` reads as "every active user in
     * the organization" (see its own docblock). A `notify` rule on either
     * trigger would then broadcast a named person's test score and answers to
     * the whole company. That leak was proven live in this codebase today, on
     * Human Resources — see `HrAutomationsTest`.
     *
     * `oldest()->oldest('id')`, not a bare `first()` — see
     * `TrainingProgress::automationScope`'s own docblock (coherence audit
     * finding 6): §4 allows several assignments per user, an unordered
     * `first()` lets MySQL pick a different one on different runs, and the
     * oldest is the one choice that does not move every time a later
     * assignment is added.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function automationScope(): array
    {
        $assignment = $this->user?->assignments()->oldest()->oldest('id')->first();

        return match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => ['franchise', $assignment->scopeId()],
            Assignment::SCOPE_COMPANY => ['company', $assignment->scopeId()],
            default => [null, null],
        };
    }
}
