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
 * How far one user has gotten through one guide.
 */
class TrainingProgress extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A guide read on is its guide's change on the live feed (the Dashboard counts move). */
    protected static array $changeParent = ['training', TrainingGuide::class, 'guide_id'];

    use HasUuids;

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'How far one person has read into one guide, and whether they finished it. '
            .'It is what tells an organization somebody has actually seen material they are '
            .'relying on that person having seen.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A progress row only means something next to the guide it tracks reading '
            .'through: it is created against that guide and removed by the cascade the '
            .'moment the guide is deleted, so nothing here outlives what it is progress '
            .'THROUGH. Unlike an attempt, there is no separate evidentiary reason to keep '
            .'it once the guide itself is gone — a passed test still proves something years '
            .'later, a reading position in a deleted guide does not.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which person this reading progress belongs to.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan a progress row from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('last_screen, completed_at', PersonalDataEntry::ACTIVITY,
                'How far they have read, and when — if ever — they finished.',
                Erasure::keep('The position is the record. Blanking it would leave a row '
                    .'saying somebody was reading a guide without saying how far, which is '
                    .'worse than a position kept against an already-anonymised account.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    protected $fillable = [
        'user_id', 'guide_id', 'last_screen', 'completed_at',
    ];

    // `last_screen` cast explicitly rather than left to whatever the driver
    // happens to return: it indexes into a JSON array and is compared with
    // `max()` in ProgressController::seen, and a value that came back a
    // numeric string from one read path would still compare correctly but
    // would echo back to the client as `"7"` instead of `7`.
    protected $casts = ['completed_at' => 'datetime', 'last_screen' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }

    /**
     * Where this progress row belongs, for §4 reach — the LEARNER'S place,
     * not the guide's.
     *
     * The two are different questions. `training_guides.company_id` /
     * `franchise_id` answer "who may SEE this guide" (§4, applied by
     * `InventoryVisibility::constrain` in LearnController and
     * ProgressController) — a guide filed at the organization is visible
     * everywhere, by design. A completion answers "who should be TOLD", and
     * delegating to the guide would fail the case that actually motivates a
     * rule here: a Riverside learner finishing an organization-wide guide
     * would resolve to `[null, null]` off the guide, so "notify their
     * supervisor" would never fire for the one learner an org-wide guide
     * reaches the most unevenly. The person's own assignment is what answers
     * "who should be told", regardless of how broadly the guide itself was
     * published.
     *
     * `training_progress` carries no place column of its own (see the
     * migration) — this is Onboarding::scope()'s shape, not a schema change:
     * a per-user record with nothing to sniff delegates to the person's own
     * assignment rather than leaving `AutomationEngine::scopeOf` and
     * `NotificationDispatcher::audienceFor` to fall through to "no scope",
     * which the latter reads as "every active user in the organization" —
     * precisely the leak `CarriesAutomationScope` exists to close (see its
     * own docblock, and `NotificationDispatcher::audienceFor`'s).
     *
     * `first()` rather than anything cleverer, matching `Onboarding::scope`'s
     * own fallback branch exactly: one user, one home assignment. But §4
     * allows a user to hold SEVERAL — with no ordering, MySQL is free to
     * hand back a different row on different runs, so which supervisor "who
     * should be told" resolves to would move around underneath an identical
     * completion (coherence audit finding 6). Ordered oldest-first: the
     * assignment given first is this person's original home place, and
     * stays their home no matter how many more places they pick up later —
     * a newest-first order would instead re-point every future
     * notification at whatever was assigned most recently, flapping scope
     * on a write this method never sees. `id` breaks a tie between two rows
     * created in the same request (e.g. `ProvisionOrganization`'s own
     * write): `HasUuids` gives this table ordered UUIDs, so it is already a
     * second, finer creation clock.
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
