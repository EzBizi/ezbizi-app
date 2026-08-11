<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Contracts\DocumentFingerprint;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\Privacy\ConsentRecord;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RecordsConsent;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The evidentiary record of somebody signing (§ESIGN/UETA,
 * EZBIZI-HR-PLAN.md — e-signature is self-built, per the owner).
 *
 * Five questions the law asks, and the columns that answer each:
 *
 *   intent          intent_statement — the exact words above the button
 *   consent         consent_statement + consented_at — agreeing to transact
 *                   electronically at all, which is a separate agreement from
 *                   the contract and is why it has its own timestamp
 *   attribution     signer_user_id + signer_label + signer_party, and
 *                   ip_address / user_agent as corroboration
 *   tamper-evidence content_hash + hash_algorithm — see DocumentFingerprint,
 *                   whose docblock is the careful statement of what a match
 *                   does and does not prove
 *   retrievability  the contract version is immutable and reachable from here,
 *                   so the questions can always be reproduced. This record
 *                   fingerprints the rendered document rather than storing it —
 *                   see below, because the reason is not the one this docblock
 *                   used to give.
 *
 * ── The ceremony is elsewhere, and so is the document ────────────
 *
 * Nothing here shows a document, collects a click or renders a PDF. Onboarding
 * does that. This is only the record, built first because evidence is the part
 * that cannot be added afterwards to signatures already collected.
 *
 * This used to say the rendered artefact "needs the attachment primitive the
 * plan defers; until it exists…". It exists — `attachments`, App\Models\
 * Attachment and App\Support\Files\FileStore shipped in the same changeset as
 * this table, and the plan lists contracts as the reason they were built first.
 * A comment that denies its own dependency is worse than no comment: the next
 * person to render a PDF reads it and concludes there is nowhere to put one.
 *
 * The true reason there is no pointer to a document here is that the attachment
 * points the other way. A file hangs off the RECORD it belongs to (see
 * App\Support\Files\Attachables), so the rendered document belongs to the
 * onboarding — the thing a person opens and asks for a copy of — and this row
 * identifies what was signed the way the evidence does: which version, by whom,
 * about whom, and the fingerprint of the exact bytes. A `document_attachment_id`
 * column here would be a second, weaker answer to "where is the paperwork", and
 * it would duplicate a relation that already has a direction.
 *
 * ── Immutable, and more strictly than a version ──────────────────
 *
 * A version is immutable so history stays readable. A signature is immutable
 * because it is the evidence: a record that can be edited after the fact is
 * indistinguishable from one that was. There is no correction path, and there
 * should not be — a signature captured wrongly is superseded by a new one, and
 * both stay, which is the plan's amendment rule ("the original stays because it
 * is what was signed; the amendment is what is true").
 *
 * How far that reaches is worth being exact about, because `content_hash` is the
 * one column a forgery would need to rewrite: change the document, change the
 * hash, and `stillMatches()` says yes to the wrong bytes. The `updating` event
 * below stops a model being saved. `WrittenOnce` stops the query builder and the
 * quiet saves that skip events — `ContractSignature::query()->update([...])` went
 * through unopposed before it. Raw SQL beneath Eloquent is not stopped by either,
 * and WriteOnceBuilder's docblock says why the line falls there.
 */
class ContractSignature extends Model implements HoldsPersonalData, RecordsConsent
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;
    use WrittenOnce;

    public const UPDATED_AT = null;

    /** What this system witnessed itself. A vendor's records carry their name. */
    public const PROVIDER_SELF = 'ezbizi';

    /**
     * Set on the model as well as defaulted in the schema.
     *
     * The column default only applies at INSERT, so a signature built in memory
     * and inspected before it is refreshed would report no provider at all —
     * and "who witnessed this" reading as null on a record that this system
     * witnessed is the wrong answer, not a missing one.
     */
    protected $attributes = ['provider' => self::PROVIDER_SELF];

    protected $fillable = [
        'organization_id', 'contract_version_id', 'field_key',
        'subject_user_id', 'signer_user_id', 'signer_label', 'signer_party',
        'ip_address', 'user_agent',
        'content_hash', 'hash_algorithm', 'content_length',
        'consent_statement', 'consented_at', 'intent_statement', 'affirmation',
        'signed_at', 'provider', 'provider_reference', 'provider_evidence',
    ];

    protected function casts(): array
    {
        return [
            'signed_at'         => 'datetime',
            'consented_at'      => 'datetime',
            'content_length'    => 'integer',
            'provider_evidence' => 'array',
        ];
    }

    /*
     * §9. This table was built in the same changeset as the data inventory and
     * was not added to it — which is exactly the omission the inventory's own
     * docblock says discovery prevents. Discovery finds implementors; it cannot
     * find a model that never declared itself, and nothing failed when this one
     * did not. See
     * DataInventoryTest::test_every_model_holding_a_person_shaped_column_declares_it
     * for the check that now does.
     */
    public static function personalDataPurpose(): string
    {
        return 'Evidence that a named person agreed to a specific document at a specific '
            .'moment. Under ESIGN/UETA it has to attribute the act to a human being, so it '
            .'is personal data by design rather than by accident — a signature nobody can '
            .'be identified from is not a signature.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the agreement matters, which outlives the account: the '
            .'name is captured verbatim rather than joined, so it survives that person being '
            .'anonymised. Deliberate, and the one place §9 gives way — you cannot erase your '
            .'way out of having signed something, and a contract whose signatory can be '
            .'removed is not enforceable.'
        );
    }

    /*
     * ── THE TABLE THAT SURVIVES ERASURE, AND WHY ─────────────────
     *
     * Every entry below is `Erasure::keep`. That is the most consequential set of
     * declarations in the privacy machinery and it is not a shortcut, so it is
     * argued here once rather than four times.
     *
     * §9 resolves erasure as anonymise-in-place: keep the record, blank the
     * person. It works because the RECORD and the PERSON are separable — an
     * invoice is still an invoice with the name taken out, and its amounts are
     * what tax law wants. A signature is the one row in this system where that
     * separation destroys the record rather than cleaning it, because the
     * personal data IS the record. ESIGN and UETA both turn on attribution: a
     * signature that cannot be attributed to a human being is not a signature,
     * it is a timestamp. Blank `signer_label` and what is left does not evidence
     * a weaker version of the agreement — it evidences nothing, and the
     * organization holding it has silently lost the contract.
     *
     * That is also why `signer_label` is a captured string rather than a join to
     * `users.name`. Anonymising the account was ANTICIPATED here: the label was
     * copied at the moment of signing precisely so that erasing the person leaves
     * the evidence standing. A purge that then blanked the copy would be defeating
     * a design decision made for its benefit.
     *
     * The honest cost, stated rather than hidden: somebody who signs a contract
     * cannot have their name removed from that contract. That is true on paper
     * too, it is what signing means, and the report says so to whoever runs the
     * purge instead of quietly leaving the row untouched.
     *
     * What bounds it is that this table holds ONLY the evidence. There is no
     * address here, no date of birth, no phone number — the contents of what was
     * agreed live in `onboarding_answers`, which erases in full. The keep is
     * narrow because the table was kept narrow.
     */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('signer_label', PersonalDataEntry::IDENTITY,
                'Who signed, captured as text at the moment of signing so it still answers '
                .'the question after the account is gone.',
                Erasure::keep('Attribution is what makes this a signature at all under '
                    .'ESIGN/UETA. It is captured verbatim rather than joined precisely so '
                    .'that it survives the account being anonymised, and erasing it would '
                    .'not weaken the evidence but destroy it.')),
            new PersonalDataEntry('affirmation', PersonalDataEntry::IDENTITY,
                'What they typed to sign. Their own name, in their own keystrokes — the '
                .'affirmative act ESIGN requires.',
                Erasure::keep('The affirmative act itself. Without it the record says an '
                    .'agreement was reached and cannot say that anybody did anything.')),
            new PersonalDataEntry('ip_address, user_agent', PersonalDataEntry::TECHNICAL,
                'Where the act came from. Attribution evidence, and the part a dispute '
                .'turns on.',
                Erasure::keep('Corroboration of attribution, and the first thing asked for '
                    .'when a signature is denied. It is kept with the signature it '
                    .'corroborates and expires with it, not before.')),
            new PersonalDataEntry('signer_user_id, subject_user_id', PersonalDataEntry::IDENTITY,
                'Who signed and who it was about, while those accounts exist.',
                Erasure::keep('Foreign keys to accounts that the purge has already '
                    .'anonymised at their one home. Nulling them here would remove the link '
                    .'between a person\'s record and their own contract without removing any '
                    .'fact about them — the name behind the key is already gone.')),
        ];
    }

    /**
     * Signatures naming this person, on either side.
     *
     * Returned even though every entry is a keep, deliberately. The purge report
     * is where somebody learns what did NOT go, and a table that answered `null`
     * here would be reported as "holds nothing about this person", which is
     * false — it holds their name, permanently, and they are entitled to be told
     * so rather than to find out later.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof User) {
            return null;
        }

        /*
         * The `orWhere` pair is NESTED, and it has to be.
         *
         * BelongsToTenant adds `organization_id = ?` as a global scope, and SQL
         * binds AND tighter than OR — so a flat `->where(signer)->orWhere(subject)`
         * compiles to
         *
         *     organization_id = X AND signer_user_id = A OR subject_user_id = A
         *
         * which matches every organization's signatures where the subject happens
         * to be A. On a purge that means erasing, or reporting, another tenant's
         * rows: §5.1's highest-severity bug class, produced by a missing pair of
         * brackets. Every `orWhere` in a personalDataAbout is grouped for this
         * reason.
         */
        return static::query()->where(fn (Builder $q) => $q
            ->where('signer_user_id', $subject->getKey())
            ->orWhere('subject_user_id', $subject->getKey()));
    }

    protected static function booted(): void
    {
        /*
         * ── The row has to make sense before it is written ───────
         *
         * This class shipped validating NOTHING, and the docblock above talked
         * about evidence the whole time. A sceptic wrote a row with
         * `field_key: 'not_a_field_at_all'`, `signer_party: 'martian'`,
         * `content_hash: 'not-a-hash'` and empty consent and intent statements.
         * It was accepted, and — because `updating` throws — it is permanently
         * uncorrectable. Immutability without validation does not protect
         * evidence; it preserves nonsense.
         *
         * The checks below are not the controller's job duplicated. The
         * controller answers 422 with a sentence somebody can act on, which is
         * the right response to a person. This is the guarantee that holds for
         * every OTHER writer — a queued job, an import, a vendor adapter, a
         * tinker session — and it is what makes "a nonsense row is impossible"
         * true rather than merely likely.
         *
         * RuntimeException rather than a validation exception, matching the two
         * refusals below it: reaching here is a programming error. A person
         * never sees this text, because the request path checks first.
         */
        static::creating(function (self $signature) {
            $signature->assertIsCoherentEvidence();
        });

        static::updating(function () {
            throw new RuntimeException(
                'A signature record is evidence and cannot be edited. If a signature was '
                .'captured wrongly, capture a new one — both stay, and the pair is what shows '
                .'what happened.'
            );
        });

        /*
         * And it cannot be DELETED, which was the larger hole.
         *
         * Only `updating` was guarded, so `$signature->delete()` succeeded — and
         * evidence that can be silently removed is weaker than evidence that can
         * be edited. The class refused the lesser risk and allowed the greater
         * one, while its docblock said there was no correction path.
         */
        static::deleting(function () {
            throw new RuntimeException(
                'A signature record cannot be deleted. It is the evidence that somebody '
                .'agreed to something, and evidence that disappears on request is not '
                .'evidence. An organization being removed takes its signatures with it; '
                .'nothing else does.'
            );
        });
    }

    /**
     * Everything that would make this row not evidence.
     *
     * Each check below corresponds to one of the five questions the law asks,
     * and refuses the way of answering it that looks like an answer and is not.
     *
     * @throws RuntimeException
     */
    public function assertIsCoherentEvidence(): void
    {
        $version = $this->contractVersion
            ?? ContractVersion::query()->find($this->contract_version_id);

        if ($version === null) {
            $this->refuse('it names no contract version, so there is no field list to say '
                .'what was signed or what the document was built from');
        }

        if ($this->organization_id !== null && $this->organization_id !== $version->organization_id) {
            $this->refuse('the version it names belongs to another organization');
        }

        /*
         * ATTRIBUTION, first half: the field has to be a real signature field.
         *
         * `field_key` is what says WHICH signature this is. A key the version
         * never declared describes a signature on a line that does not exist,
         * and a key that exists but is a text field describes somebody typing
         * into a box as if it were an act of signing.
         */
        $field = $version->field((string) $this->field_key);

        if ($field === null) {
            $this->refuse(sprintf(
                "'%s' is not a field on version %d of that contract",
                (string) $this->field_key, $version->version,
            ));
        }

        if ($field->type !== FieldType::SIGNATURE) {
            $this->refuse(sprintf(
                "'%s' is a %s field, not a signature field",
                $field->key, FieldType::ALL[$field->type] ?? $field->type,
            ));
        }

        /*
         * ATTRIBUTION, second half: the party is the FIELD's, not the writer's
         * opinion.
         *
         * FieldSet already refuses `either` on a signature, precisely so that a
         * signature field belongs to exactly one side. That makes the correct
         * value of this column derivable — so anything else is either a mistake
         * or an employer's signature filed as the employee's, which is the
         * misattribution that voids it.
         */
        if ($this->signer_party !== $field->filledBy) {
            $this->refuse(sprintf(
                "it is filed as a '%s' signature, but '%s' is the %s's to sign",
                (string) $this->signer_party, $field->key,
                $field->filledBy === FilledBy::EMPLOYER ? 'employer' : 'employee',
            ));
        }

        if (! is_string($this->signer_label) || trim($this->signer_label) === '') {
            $this->refuse('it does not say who signed. The label is captured at the moment '
                .'so the question survives the account being deleted, and a blank one '
                .'defeats the only reason it is stored');
        }

        if ($this->subject_user_id === null) {
            $this->refuse('it is not about anybody. A signature has a subject — the person '
                .'the contract is for — and one without is a record nobody can be shown');
        }

        if ($this->provider === self::PROVIDER_SELF && $this->signer_user_id === null) {
            $this->refuse('this system witnessed it and cannot say who performed the act. '
                .'A vendor record may carry attribution in provider_evidence instead; a '
                .'self-witnessed one has nothing else to fall back on');
        }

        /*
         * TAMPER EVIDENCE. `content_hash` and `hash_algorithm` are the whole of
         * it, and both were free-text columns.
         *
         * The algorithm has to be one DocumentFingerprint will VERIFY against —
         * anything else produces a record that can never answer "is this still
         * the document", because `matches()` refuses unknown algorithms. And the
         * hash has to be the right shape for it: 'not-a-hash' is not a sha256,
         * and a record carrying it looks verifiable and is not.
         *
         * The expected length is computed rather than tabulated, so adding an
         * algorithm to VERIFIABLE needs no second edit here.
         */
        if (! in_array((string) $this->hash_algorithm, DocumentFingerprint::VERIFIABLE, true)) {
            $this->refuse(sprintf(
                "'%s' is not an algorithm this software can verify against (%s). A record "
                .'written with one it cannot check is a record that answers "no" forever',
                (string) $this->hash_algorithm,
                implode(', ', DocumentFingerprint::VERIFIABLE),
            ));
        }

        $expected = strlen(hash((string) $this->hash_algorithm, ''));

        if (! is_string($this->content_hash)
            || ! preg_match('/^[0-9a-f]{'.$expected.'}$/', $this->content_hash)) {
            $this->refuse(sprintf(
                'its content_hash is not a %s digest — that is %d hexadecimal characters, '
                .'and what is there is %d. A fingerprint that cannot have come from the '
                .'algorithm beside it proves nothing about any document',
                (string) $this->hash_algorithm, $expected, strlen((string) $this->content_hash),
            ));
        }

        if ((int) $this->content_length <= 0) {
            $this->refuse('it records an empty document. Nobody signs nothing, and a length '
                .'of zero removes the cheap second signal that a file in somebody\'s hand is '
                .'a different document rather than the same one encoded differently');
        }

        /*
         * CONSENT and INTENT, stored as the words that were on screen. Blank
         * ones are the failure the columns were chosen to prevent: a boolean
         * records that somebody ticked something and cannot say what, and an
         * empty statement is a boolean with extra steps.
         */
        foreach ([
            'consent_statement' => 'the words they were shown about transacting electronically',
            'intent_statement'  => 'the words they were shown above the signing control',
            'affirmation'       => 'what they actually did — the name they typed, or the mark they drew',
        ] as $column => $what) {
            if (! is_string($this->{$column}) || trim($this->{$column}) === '') {
                $this->refuse("it has no {$column}: {$what}. Storing the sentence is what "
                    .'makes each record self-describing when the wording is revised');
            }
        }

        if ($this->consented_at === null || $this->signed_at === null) {
            $this->refuse('consent and signature both have to be timed. They are separate '
                .'agreements — one to transact electronically at all, one to this document '
                .'— which is why they have separate timestamps');
        }

        if (! is_string($this->provider) || trim($this->provider) === '') {
            $this->refuse('it does not say who witnessed it. "'.self::PROVIDER_SELF.'" is '
                .'what this system records of its own');
        }
    }

    private function refuse(string $because): never
    {
        throw new RuntimeException(
            'Refusing to write a signature record, because '.$because.'. A signature cannot '
            .'be corrected once written — updates and deletes both throw — so a row that is '
            .'wrong is wrong permanently, and refusing it now is the only chance there is.'
        );
    }

    /**
     * ── The one consent this product actually takes (§9) ─────────
     *
     * ESIGN's agreement to transact electronically, which the five-question
     * docblock at the top of this class already calls out as "a separate
     * agreement from the contract and is why it has its own timestamp". That
     * separateness is exactly what makes it a consent rather than a term: the
     * person is agreeing to the MEDIUM, and they are asked before they are bound.
     *
     * It was already stored correctly — the verbatim sentence in
     * `consent_statement`, the moment in `consented_at` — before any of the
     * privacy machinery existed. So nothing here writes anything. This method
     * makes an existing record readable as what it is, which is the whole
     * difference between "consent absent" and "consent unlisted".
     *
     * The `affirmation` is given as the ACT rather than the consent's own text,
     * because ESIGN's consent and the signature were taken in one motion: the
     * name they typed is what performed both, and a ledger claiming a separate
     * gesture that never happened would be describing a ceremony this software
     * does not run.
     *
     * @return ConsentRecord[]
     */
    public static function consentsGivenBy(User $subject): array
    {
        return static::query()
            ->where('signer_user_id', $subject->getKey())
            ->orderByDesc('consented_at')
            ->get()
            ->map(fn (self $signature) => new ConsentRecord(
                kind:  'electronic_transaction',
                label: 'Agreeing to sign and receive this agreement electronically',
                // Verbatim, from the row. Never re-composed from Ceremony: that
                // class's own docblock turns on each record keeping the sentence
                // it was signed under, so revising the wording changes what the
                // next signer reads and nothing about what this one agreed to.
                statement: (string) $signature->consent_statement,
                givenAt:   $signature->consented_at?->toIso8601String(),
                act:       'Typed "'.$signature->affirmation.'" to sign, having been shown '
                    .'the statement above first.',
                evidenceType: 'contract_signature',
                evidenceId:   (string) $signature->getKey(),
                withdrawal: 'Cannot be withdrawn, and withdrawing it would not undo anything. '
                    .'This consent is to using an electronic medium; the agreement it was '
                    .'given for has already been reached, and a signature is not unsigned by '
                    .'objecting to how it was collected. A paper copy can be asked for at any '
                    .'time — the statement itself says so.',
            ))
            ->all();
    }

    public function contractVersion(): BelongsTo
    {
        return $this->belongsTo(ContractVersion::class);
    }

    /** The employee the contract is about. */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /** Whoever performed this signing act — on an employer signature, not the subject. */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signer_user_id');
    }

    /**
     * Is this document still the one that was signed?
     *
     * The whole tamper-evidence claim reduces to this call. Read
     * DocumentFingerprint's docblock before relying on a `true` for anything
     * beyond "these bytes are unchanged" — it says exactly what that is worth
     * and what it is not.
     */
    public function stillMatches(string $renderedContent): bool
    {
        return DocumentFingerprint::matches(
            $renderedContent,
            (string) $this->content_hash,
            (string) $this->hash_algorithm,
        );
    }
}
