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
 * A guide or test given to somebody, with an optional due date.
 *
 * `company_id`/`franchise_id` (see the migration) name where this row is
 * FILED — copied from whichever target it carries when it is written
 * (`AssignmentWriter::create`), the same place a guide or test itself
 * carries (TrainingGuidePolicy, TrainingTestPolicy). [automationScope] below
 * answers a different question — see its own docblock.
 */
class TrainingAssignment extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed where it assigns. */
    protected static string $changeType = 'training';


    public const USER = 'user';

    public const ROLE = 'role';

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'Who was told to complete a guide or test, by when, and who assigned it. It '
            .'is the record that turns a published guide into a requirement somebody was '
            .'actually given.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'An assignment only means something next to the guide or test it points at: it '
            .'is removed by the cascade the moment that guide or test is deleted, so '
            .'nothing here outlives what it is an assignment TO. Unlike an attempt, there '
            .'is no separate evidentiary reason to keep an instruction to do training once '
            .'the training itself no longer exists to be done.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('assignee_id', PersonalDataEntry::IDENTITY,
                'Who this was assigned to — a person, only when assignee_type is "user"; '
                .'when it is "role" the same column holds a role id, which names nobody.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan an assignment from the '
                    .'person it is about without removing anything about them.')),
            new PersonalDataEntry('assigned_by_user_id', PersonalDataEntry::IDENTITY,
                'Who created the assignment. Nullable — an assignment does not stop being '
                .'about its assignee just because nobody, or a removed account, made it.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would erase who assigned the training '
                    .'without removing anything about the person the account belongs to.')),
        ];
    }

    /**
     * An assignment is a fact about two people: the one who received it, and
     * the one who made it. `assignee_id` only names a person when
     * `assignee_type` is "user" — for a "role" assignment that same column
     * holds a role id, and matching it against a user's id would report a
     * coincidence of UUIDs as a fact about a person. The AND pair is nested
     * inside the outer `where` so the tenant scope's global `organization_id`
     * predicate binds around the whole disjunction rather than only its first
     * half — see ContractSignature::personalDataAbout for why every `orWhere`
     * here is grouped rather than left flat.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }

        return static::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $a) => $a
                ->where('assignee_type', self::USER)
                ->where('assignee_id', $subject->getKey()))
            ->orWhere('assigned_by_user_id', $subject->getKey()));
    }

    protected $fillable = [
        'company_id', 'franchise_id', 'guide_id', 'test_id',
        'assignee_type', 'assignee_id', 'due_on', 'assigned_by_user_id',
    ];

    protected $casts = ['due_on' => 'date'];

    public function guide(): BelongsTo
    {
        return $this->belongsTo(TrainingGuide::class, 'guide_id');
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(TrainingTest::class, 'test_id');
    }

    /**
     * Where this assignment belongs, for §4 reach — the LEARNER'S place, not
     * this row's own `company_id`/`franchise_id`.
     *
     * Copied from {@see TrainingProgress::automationScope()} — the settled
     * shape for this module, reviewed twice there. The two questions are
     * different for the identical reason TrainingProgress's own docblock
     * gives: this row's `company_id`/`franchise_id` answer who may SEE it
     * filed (§4, applied the way TrainingGuidePolicy/TrainingTestPolicy
     * apply it to the content itself) — an organization-wide guide is filed
     * everywhere, by design. A `training.assigned` notification answers who
     * should be TOLD, and that is the learner's own place, not the
     * content's.
     *
     * A ROLE assignment has no single learner — `assignee_id` names a role,
     * not a user, so `User::find` on it finds nobody and this falls through
     * to `[null, null]` exactly the way an unplaced person does. That is the
     * conservative, organization-wide answer `NotificationDispatcher::audienceFor`
     * already gives "no narrower place" (its own docblock) — the right
     * default for an event that might be about anybody the role is ever
     * held by, present or future (decision 1: a role assignment is one row,
     * never expanded).
     *
     * A subject without `CarriesAutomationScope` falls through
     * `NotificationDispatcher::audienceFor` to EVERY ACTIVE USER in the
     * organization — implementing this is not optional (training task-9
     * brief).
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
        $learner = $this->assignee_type === self::USER
            ? User::query()->find($this->assignee_id)
            : null;

        $assignment = $learner?->assignments()->oldest()->oldest('id')->first();

        return match ($assignment?->scopeType()) {
            Assignment::SCOPE_FRANCHISE => ['franchise', $assignment->scopeId()],
            Assignment::SCOPE_COMPANY => ['company', $assignment->scopeId()],
            default => [null, null],
        };
    }
}
