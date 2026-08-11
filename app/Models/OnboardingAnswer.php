<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FilledBy;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\MasksSensitiveValues;
use App\Support\Privacy\PersonalDataEntry;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * One answer to one tenant-defined question — and the first row in this
 * application whose sensitivity is decided by DATA rather than by its class.
 *
 * ── Why this is the model HoldsSensitiveValues was shaped for ────
 *
 * That interface has an instance method rather than a static list, and its
 * docblock says why: "For a contract answer, one row of the same table is
 * sensitive and the next is not, because the field definitions behind them
 * differ." Until now nothing implemented it, so the claim was untested. This is
 * the implementation it describes — `sensitiveAttributes()` asks the pinned
 * contract version what the tenant said about THIS row's field.
 *
 * Both halves are needed and only one of them is obvious:
 *
 *   HoldsSensitiveValues   names which attribute is sensitive on this row
 *   MasksSensitiveValues   makes toArray/toJson/response()->json answer the mask
 *
 * Without the trait, an answer nested in an eager-loaded relation publishes the
 * clear value through every ordinary serialisation path while `read()` — the
 * only thing that masks — is called from one endpoint. That mistake has already
 * been made once in this codebase; the trait's own docblock is the record of it.
 *
 * ── `value` is encrypted on EVERY row ────────────────────────────
 *
 * Sensitive or not. A cast is per-column and cannot be turned on for one row and
 * off for the next, and the alternative — encrypt only what is flagged — means
 * that marking a field sensitive AFTER answers exist leaves the old ones sitting
 * in the clear, which is exactly when somebody realises a field should have been
 * flagged. See HoldsSensitiveValues for the full argument.
 */
class OnboardingAnswer extends Model implements HoldsPersonalData, HoldsSensitiveValues
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use MasksSensitiveValues;

    protected $fillable = [
        'organization_id', 'onboarding_id', 'field_key', 'value',
        'set_by_user_id', 'set_at',
    ];

    protected function casts(): array
    {
        return [
            'value'  => 'encrypted',
            'set_at' => 'datetime',
        ];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'What somebody answered on their onboarding form. The questions are the '
            .'employer\'s own — this platform does not decide what is asked — so what is '
            .'held here differs from one organization to the next, and often from one '
            .'contract to the next within the same one.';
    }

    public static function personalDataRetention(): string
    {
        return 'Kept for as long as the contract they belong to. These are the terms that '
            .'were agreed, so they outlive the employment: the answers and the document '
            .'rendered from them are the same record seen two ways.';
    }

    public static function personalDataHeld(): array
    {
        return [
            /*
             * The entry §9 says an erasure request cannot be answered without.
             *
             * "Nobody except the tenant knows that the field they called `acct`
             * is a bank account." So the honest inventory entry is not a list of
             * fields — it is a pointer to where the list lives, flagged
             * `tenantDefined` so the reader knows to go and look rather than
             * believing this row is the whole answer.
             */
            new PersonalDataEntry('value', PersonalDataEntry::CONTENT,
                'Whatever the contract template asked for — a National Insurance number, '
                .'a bank account, a next of kin, a driving licence number. The list of '
                .'questions is in the contract version this onboarding was pinned to, and '
                .'each question carries the employer\'s own answer to whether it is '
                .'sensitive. Every value is encrypted at rest whether it was flagged or '
                .'not; the flag additionally masks it, keeps it out of exports, and makes '
                .'every reveal an audit event.',
                tenantDefined: true,
                encrypted: true),
            new PersonalDataEntry('set_by_user_id, set_at', PersonalDataEntry::ACTIVITY,
                'Who last wrote this answer and when. It exists because a field marked '
                .'"either" can be written by both sides, and "the employer typed it" and '
                .'"I corrected it" have to stay distinguishable.'),
        ];
    }

    // ── relations ────────────────────────────────────────────────

    public function onboarding(): BelongsTo
    {
        return $this->belongsTo(Onboarding::class);
    }

    public function setBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by_user_id');
    }

    // ── the field this answers ───────────────────────────────────

    /**
     * The definition behind this answer, out of the PINNED version.
     *
     * Retired fields included — an answer to a field retired last month still
     * needs its label to be readable, which is the entire difference between
     * retiring a field and deleting one.
     *
     * Resolved through the relation, so a caller that has the onboarding in hand
     * can hand it over with `setRelation('onboarding', $onboarding)` and turn a
     * per-answer lookup into none. Left to lazy-load otherwise, because the wrong
     * answer here is a disclosure and the wrong answer must not be the cheap one.
     */
    public function fieldDefinition(): ?FieldDefinition
    {
        return $this->onboarding?->contractVersion?->field($this->field_key);
    }

    /**
     * Which side last wrote this.
     *
     * Derived rather than stored: the employee is the onboarding's `user_id`, so
     * a column here would be a second answer to a question `set_by_user_id`
     * already answers, and the row where the two disagreed would be the one
     * nobody could explain.
     *
     * A NULL setter reads as the employer. That happens when the account that
     * wrote it has since been deleted, and the employer is the safe reading:
     * claiming the employee typed something when the evidence has gone is the
     * direction that misattributes.
     */
    public function setByParty(): string
    {
        return $this->set_by_user_id !== null
            && $this->set_by_user_id === $this->onboarding?->user_id
                ? FilledBy::EMPLOYEE
                : FilledBy::EMPLOYER;
    }

    // ── HoldsSensitiveValues ─────────────────────────────────────

    /**
     * Sensitive if the TENANT said this field was, and masked when we cannot ask.
     *
     * The null case is not defensive habit. This is called from
     * `attributesToArray()`, so it runs on the path that puts the value on the
     * wire — and if the pinned version cannot be read (a row mid-construction, a
     * key that is not in the version at all), answering "not sensitive" publishes
     * a value nobody has decided about. Masking an ordinary answer is a nuisance;
     * publishing a bank account is the failure this whole mechanism exists to
     * prevent, so the unknown case takes the nuisance.
     */
    public function sensitiveAttributes(): array
    {
        $field = $this->fieldDefinition();

        return $field !== null && ! $field->sensitive ? [] : ['value'];
    }

    /**
     * May this person see it in the clear?
     *
     * Delegated to the policy rather than answered here, so that "who may reveal
     * an onboarding answer" has one home — the same home as who may read the
     * onboarding and who may write to it. A permission check written here would
     * be a second copy of a rule that also has to know about §4 reach, and the
     * copy that drifted would be the one that let somebody read another
     * franchise's bank details.
     *
     * The plan leaves one case open and the policy is where it is answered: the
     * person the data is ABOUT can always see their own.
     */
    public function mayReveal(User $actor): bool
    {
        return $this->onboarding !== null
            && Gate::forUser($actor)->allows('reveal', $this->onboarding);
    }
}
