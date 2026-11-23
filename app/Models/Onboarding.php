<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One person joining, under one pinned contract version
 * (EZBIZI-HR-PLAN.md, "Onboarding").
 *
 * The form the two sides fill, the answers they give, the signature that closes
 * it and the document that comes out. Everything about WHAT is asked comes from
 * `contractVersion` — this model knows there are fields, that each has a key and
 * somebody who fills it, and never what any of them mean.
 *
 * ── The two sides, in one column ─────────────────────────────────
 *
 * `user_id` is the employee. Everybody else acting on this form is the employer.
 * That single fact is what `filled_by` is enforced against, and it is why the
 * party of an answer never needed a column of its own: the setter is stored, and
 * comparing it to this column answers which side wrote it.
 *
 * Before the invitation is accepted there is no account, so `user_id` is null
 * and only the employer can be filling anything in. That is the ordinary shape
 * of an onboarding that has been started and pre-filled, not an incomplete one.
 */
class Onboarding extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its invitation's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['hr_invitations', Invitation::class, 'invitation_id'];

    use HasAttachments;
    use HasFactory;
    use HasUuids;

    /**
     * Somebody is filling it in. Both sides may write, in any order, as often as
     * they like — partial answers are the normal state of a form, not an error.
     */
    public const IN_PROGRESS = 'in_progress';

    /**
     * Complete, and the first party has signed. The answers are frozen from
     * here: see App\Services\Hr\OnboardingForm for why a document somebody has
     * already signed cannot go on changing underneath them.
     */
    public const SUBMITTED = 'submitted';

    /**
     * Both sides have signed and the sender's final approval is pending (Jose,
     * 2026-10-05), when the organization asks for one: Settings › Human
     * Resources, `hr.onboarding_approval`. Until it is given the new starter
     * sees only their paperwork (User::awaitingOnboardingApproval).
     */
    public const AWAITING_APPROVAL = 'awaiting_approval';

    /** Every signature the version asks for has been given, and approved where that is asked. */
    public const COMPLETED = 'completed';

    /**
     * Stopped before it became an agreement. The row stays, the form does not
     * move, and the invitation is free for a new start — see the cancel
     * endpoint. A fully signed onboarding cannot become this.
     */
    public const CANCELLED = 'cancelled';

    /**
     * The company address typed on the form, sent among the answers but not a
     * contract question: it overrides the address employer() reads.
     */
    public const EMPLOYER_ADDRESS = 'employer_address';

    /**
     * The field key a template gives its Social Security card photo (Merav,
     * 2026-11-09): a required, sensitive file field. The HR record reads it to
     * show "on file" or "missing" for people hired before it was asked.
     */
    public const SSN_CARD = 'ssn_card';

    public const STATUSES = [
        self::IN_PROGRESS, self::SUBMITTED, self::AWAITING_APPROVAL, self::COMPLETED, self::CANCELLED,
    ];

    /** Not finished: still being filled in, signed, or approved. */
    public const OPEN = [self::IN_PROGRESS, self::SUBMITTED, self::AWAITING_APPROVAL];

    /** The organization's switch for the final approval. */
    public const APPROVAL_SETTING = 'hr.onboarding_approval';

    protected $fillable = [
        'organization_id', 'invitation_id', 'user_id', 'contract_version_id',
        'company_id',
        'status', 'started_at', 'submitted_at', 'completed_at', 'cancelled_at',
        'started_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at'   => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'The paperwork of somebody joining: which contract they are being taken on '
            .'under, when the form was started, when they submitted it and when it was '
            .'finished. The answers themselves are in a table of their own.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the agreement it produced matters, which outlives the '
            .'employment. It is the index to a signed contract, and a signed contract whose '
            .'paperwork can be deleted is one nobody can produce when it is disputed. An '
            .'age-based window here would delete the paperwork of the longest-standing '
            .'employees first, which is precisely backwards. The one thing that does change '
            .'with time is the link back to the invitation that started it: invitations are '
            .'removed after two years, and this record then stops naming one. Everything '
            .'about the agreement itself stays.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Who this onboarding is about — the employee whose contract it is.',
                Erasure::keep('A foreign key to the account, which the purge anonymises at '
                    .'its one home. Nulling it here would orphan the paperwork from the '
                    .'person it belongs to without removing anything about them — and an '
                    .'onboarding nobody can attach to an employee is a contract nobody can '
                    .'produce.')),
            new PersonalDataEntry('started_by_user_id', PersonalDataEntry::ACTIVITY,
                'Who at the employer started it, so the paperwork can be traced to the '
                .'person who set it going.',
                Erasure::keep('A foreign key to a DIFFERENT person — whoever at the '
                    .'employer began the paperwork. Erasing the subject must not remove the '
                    .'provenance of the record from somebody else.')),
            new PersonalDataEntry('started_at, submitted_at, completed_at, cancelled_at',
                PersonalDataEntry::ACTIVITY,
                'When each stage happened. Part of the evidentiary record: "when did I '
                .'agree to this" is answered from here and from the signature. Cancelled '
                .'is the act of stopping before there was an agreement.',
                Erasure::keep('Timestamps, and the ones a signature is checked against. '
                    .'"When did I agree to this" is answered from here; a blank answer is '
                    .'not a private one, it is a broken contract record.')),
        ];
    }

    /**
     * The paperwork about this person, or started by them.
     *
     * Both, even though every entry is a keep, so the purge report can say that
     * an onboarding exists and is staying — see ContractSignature for the same
     * reasoning. What actually erases under this row is `onboarding_answers`,
     * which is where the personal data that was collected lives.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }

        // Grouped, so a caller narrowing this Builder narrows the whole
        // disjunction. See ContractSignature::personalDataAbout for the full
        // argument, and for why the tenant scope is not what it defends against.
        return static::query()->where(fn (Builder $q) => $q
            ->where('user_id', $subject->getKey())
            ->orWhere('started_by_user_id', $subject->getKey()));
    }

    // ── relations ────────────────────────────────────────────────

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /** The company it is under, or null for the organization (2026-10-01). */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Who the contract names as the employer: the company it is under, or the
     * organization (Ana's Suggestion, 2026-10-01).
     *
     * @return array{name: string, address: string}
     */
    public function employer(bool $typed = true): array
    {
        // Typed on this onboarding, it wins (Jose, 2026-10-05); then the
        // branch they are hired into (Merav, 2026-11-19: the address on the
        // contract must be THEIR branch's), then the company, then the business.
        $override = $typed ? trim((string) $this->employer_address) : '';
        if ($override === '') {
            $override = trim((string) $this->branch()?->address);
        }
        $company = $this->company;
        if ($company === null) {
            return ['name' => (string) $this->organization->name,
                'address' => $override !== '' ? $override : (string) ($this->organization->address ?? '')];
        }

        $region = trim(($company->state ?? '').' '.($company->postal_code ?? ''));

        return [
            'name' => (string) $company->name,
            'address' => $override !== '' ? $override : implode(', ', array_filter([
                $company->address_line1, $company->address_line2, $company->city, $region,
            ], fn ($part) => trim((string) $part) !== '')),
        ];
    }

    /**
     * The branch this hire works at: the one their invitation placed them in,
     * else their only branch assignment. Null when that is not one branch.
     */
    public function branch(): ?Franchise
    {
        $id = $this->invitation?->assigned_franchise_id;
        if ($id === null && $this->user_id !== null) {
            $ids = Assignment::query()->where('user_id', $this->user_id)
                ->whereNotNull('assigned_franchise_id')->pluck('assigned_franchise_id');
            $id = $ids->count() === 1 ? $ids->first() : null;
        }

        return $id === null ? null : Franchise::query()->find($id);
    }

    /** The employee. Null until the invitation is accepted. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    /** The PINNED version — the questions as they were when this form began. */
    public function contractVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(OnboardingAnswer::class);
    }

    /** Newest first: "what is true now" is what a screen opens on. */
    public function documents(): HasMany
    {
        return $this->hasMany(OnboardingDocument::class)->orderByDesc('revision');
    }

    /**
     * Every signature taken against this onboarding's pinned version.
     *
     * A method rather than a relation, deliberately. `contract_signatures` does
     * not point at an onboarding — its migration explains why it identifies a
     * signature the way the evidence itself does, by version and subject — so
     * the only way to express this as a `hasMany` would be a relation carrying a
     * `where` on `$this->user_id`. That kind of relation is a trap: it reads
     * correctly on one loaded model and silently applies the FIRST parent's
     * subject to every row when eager-loaded across a list.
     *
     * @return Collection<int, ContractSignature>
     */
    public function takenSignatures(): Collection
    {
        // This onboarding's own, and older rows from before signatures named
        // their onboarding, by version and subject.
        return ContractSignature::query()
            ->where('contract_version_id', $this->contract_version_id)
            ->where(fn ($q) => $q->where('onboarding_id', $this->getKey())
                ->when($this->user_id !== null, fn ($q) => $q->orWhere(fn ($q) => $q
                    ->whereNull('onboarding_id')->where('subject_user_id', $this->user_id))))
            ->orderBy('signed_at')
            ->get();
    }

    /**
     * A rendered document is not an upload, and cannot be detached.
     *
     * `attachments` carries two very different kinds of file for this record. A
     * FILE ANSWER is somebody's licence: they attached it, they can replace it,
     * and removing it is an ordinary act. A CONTRACT DOCUMENT is the artefact a
     * signature fingerprints — remove it and `stillMatches()` has nothing to
     * compare against, so the tamper-evidence quietly becomes an assertion about
     * a file nobody can produce.
     *
     * `OnboardingDocument` refuses its own deletion, and that was not enough on
     * its own: `onboarding_documents.attachment_id` cascades, so deleting the
     * ATTACHMENT through the ordinary file endpoint took the row with it without
     * the model ever hearing about it. This is the half that was missing.
     */
    /**
     * A rendered contract carries every sensitive answer in the clear.
     *
     * It has to — it is the document being signed, and one with the bank
     * account starred out is not the document. So reading it is a reveal, and
     * is authorized and audited as one, exactly as the /document endpoint is.
     *
     * A file somebody UPLOADED as an answer is a different case and returns
     * null here: the sensitivity flag on a `file` field masks the attachment
     * id, which is a pointer of no consequence, while the file itself is
     * governed by the subject's own policy. That gap is real and is recorded in
     * EZBIZI-TODO.md rather than papered over here — encrypting uploaded files
     * at rest is a storage change, not a controller one.
     *
     * @return string[]|null
     */
    public function attachmentDisclosesSensitive(Attachment $attachment): ?array
    {
        $isRenderedContract = $this->documents()
            ->where('attachment_id', $attachment->getKey())
            ->exists();

        if (! $isRenderedContract) {
            // An upload answering a sensitive file field — a Social Security
            // card photo (Merav, 2026-11-09) — is itself the sensitive value.
            $keys = $this->answers()->whereIn('field_key', $this->sensitiveAnswerKeys())->get()
                ->filter(fn (OnboardingAnswer $a) => $a->getAttribute('value') === $attachment->getKey())
                ->pluck('field_key')->values()->all();

            return $keys === [] ? null : $keys;
        }

        $keys = $this->sensitiveAnswerKeys();

        return $keys === [] ? null : $keys;
    }

    public function attachmentRemovalRefusal(Attachment $attachment): ?string
    {
        $document = $this->documents()->where('attachment_id', $attachment->getKey())->first();

        if ($document === null) {
            return null;
        }

        return sprintf(
            'That file is revision %d of this contract, not something attached to it. It is '
            .'the document a signature was taken over, so removing it would leave the '
            .'signature pointing at bytes nobody can produce. Corrections are made by '
            .'amending, which keeps both.',
            $document->revision,
        );
    }

    /** The document as it stands — revision 1, or the latest amendment. */
    public function currentDocument(): ?OnboardingDocument
    {
        return $this->documents()->first();
    }

    /** What was SIGNED, which an amendment never replaces. */
    public function signedDocument(): ?OnboardingDocument
    {
        return $this->documents()->where('revision', 1)->first();
    }

    // ── shape ────────────────────────────────────────────────────

    /**
     * The fields this form asks, in order — the template's, live only.
     *
     * Retired fields are excluded here and nowhere else, which is the whole
     * difference between retiring and deleting: a version pinned before a field
     * was retired still carries it, and a record that has an answer to it can
     * still read its label through `ContractVersion::field()`.
     *
     * @return FieldDefinition[]
     */
    public function fields(): array
    {
        return $this->contractVersion->liveFields();
    }

    /**
     * The keys whose answers the tenant marked sensitive.
     *
     * Asked of the PINNED version, so a field's sensitivity is whatever it was
     * when this onboarding started — the same rule the answer rows use, and the
     * reason HoldsSensitiveValues is an instance question rather than a class
     * one.
     *
     * @return string[]
     */
    public function sensitiveAnswerKeys(): array
    {
        return array_values(array_map(
            fn (FieldDefinition $f) => $f->key,
            array_filter($this->fields(), fn (FieldDefinition $f) => $f->sensitive),
        ));
    }

    /**
     * Does anything here need protecting?
     *
     * Read before the document is served: a contract with no sensitive field is
     * an ordinary document, and requiring the reveal permission for it would
     * lock people out of paperwork that holds nothing worth locking.
     */
    public function hasSensitiveAnswers(): bool
    {
        return $this->sensitiveAnswerKeys() !== [];
    }

    /** @return FieldDefinition[] the signature fields, which are what close it */
    public function signatureFields(): array
    {
        return array_values(array_filter(
            $this->fields(),
            fn (FieldDefinition $f) => $f->type === FieldType::SIGNATURE,
        ));
    }

    /**
     * Which side is this person on?
     *
     * The employee is the subject. Anybody else who has got this far has been
     * through the policy, so they are acting for the employer.
     *
     * Returns BOTH when somebody is onboarding themselves — an owner taking on a
     * contract in their own business, which is a real case and not a loophole.
     * They are genuinely the employee (it is their contract) and genuinely the
     * employer (they hold the authority), so refusing one of the two would make
     * a legitimate onboarding impossible to complete. A signature is unaffected:
     * its party comes from the field's own `filled_by`, never from this.
     *
     * @return string[] subset of FilledBy::EMPLOYER / FilledBy::EMPLOYEE
     */
    public function partiesFor(User $actor, bool $mayActForEmployer): array
    {
        $parties = [];

        if ($this->user_id !== null && $actor->getKey() === $this->user_id) {
            $parties[] = FilledBy::EMPLOYEE;
        }

        if ($mayActForEmployer) {
            $parties[] = FilledBy::EMPLOYER;
        }

        return $parties;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null || $this->status === self::CANCELLED;
    }

    /**
     * Frozen: the form has been signed and only an amendment may change it,
     * or it was cancelled and nothing on it moves.
     */
    public function isFrozen(): bool
    {
        return $this->submitted_at !== null || $this->isCancelled();
    }

    /**
     * Has EVERY party who must sign, signed?
     *
     * Distinct from isFrozen(), and the distinction matters: frozen begins at
     * the FIRST signature and means the answers stop moving, while this means
     * the ceremony is over. Between them is the window where one party has
     * committed and another has not, and in that window the document must hold
     * absolutely still — a signature is worth something only if every party
     * fingerprinted the same bytes.
     *
     * Amendment was gated on isFrozen(), which let the terms be rewritten
     * between two signatures. See HrOnboardingsController::amend.
     */
    public function isFullySigned(): bool
    {
        $signed = $this->takenSignatures()->pluck('field_key')->all();

        foreach ($this->signatureFields() as $field) {
            if (! in_array($field->key, $signed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where this onboarding sits in the business, for §4 reach.
     *
     * From the invitation while there is one, because that is where the employer
     * decided the person's place; from their assignment once they have an
     * account, because by then the assignment is the truth and the invitation is
     * history. Falls back to the organization, which only somebody standing at
     * the organization can reach — the safe direction when there is nothing
     * narrower to ask about.
     *
     * @return array{type: string, id: string}
     */
    public function scope(): array
    {
        if ($this->invitation !== null) {
            return ['type' => $this->invitation->scopeType(), 'id' => $this->invitation->scopeId()];
        }

        $assignment = $this->user?->assignments()->first();

        if ($assignment !== null) {
            return ['type' => $assignment->scopeType(), 'id' => $assignment->scopeId()];
        }

        return ['type' => Assignment::SCOPE_ORGANIZATION, 'id' => $this->organization_id];
    }

    /**
     * The same place, in the two words the automation engine matches on.
     *
     * Derived from `scope()` rather than worked out again, because a rule that
     * fires on somebody's onboarding and a person who can open it have to be
     * answering the same question about the same record — two derivations would
     * eventually disagree, and the disagreement would be one franchise's manager
     * being told about another franchise's new starter.
     *
     * The organization fallback becomes no scope at all, which reads to
     * `Automation::coversScope` as "only an organization-wide rule covers this"
     * — the same conservative direction `scope()` takes for the same case.
     */
    public function automationScope(): array
    {
        $scope = $this->scope();

        return match ($scope['type']) {
            Assignment::SCOPE_FRANCHISE => ['franchise', $scope['id']],
            Assignment::SCOPE_COMPANY   => ['company', $scope['id']],
            default                     => [null, null],
        };
    }
}
