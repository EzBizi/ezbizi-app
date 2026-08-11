<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Contracts\DocumentFingerprint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
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
 *                   so the questions can always be reproduced. The rendered
 *                   artefact itself needs the attachment primitive the plan
 *                   defers; until it exists, this record fingerprints a document
 *                   it does not store.
 *
 * ── The ceremony is elsewhere ────────────────────────────────────
 *
 * Nothing here shows a document, collects a click or renders a PDF. Onboarding
 * does that. This is only the record, built first because evidence is the part
 * that cannot be added afterwards to signatures already collected.
 *
 * ── Immutable, and more strictly than a version ──────────────────
 *
 * A version is immutable so history stays readable. A signature is immutable
 * because it is the evidence: a record that can be edited after the fact is
 * indistinguishable from one that was. There is no correction path, and there
 * should not be — a signature captured wrongly is superseded by a new one, and
 * both stay, which is the plan's amendment rule ("the original stays because it
 * is what was signed; the amendment is what is true").
 */
class ContractSignature extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

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
     * did not. See ContractSignatureInventoryTest for the check that now does.
     */
    public static function personalDataPurpose(): string
    {
        return 'Evidence that a named person agreed to a specific document at a specific '
            .'moment. Under ESIGN/UETA it has to attribute the act to a human being, so it '
            .'is personal data by design rather than by accident — a signature nobody can '
            .'be identified from is not a signature.';
    }

    public static function personalDataRetention(): string
    {
        return 'Kept for as long as the agreement matters, which outlives the account: the '
            .'name is captured verbatim rather than joined, so it survives that person being '
            .'anonymised. Deliberate, and the one place §9 gives way — you cannot erase your '
            .'way out of having signed something, and a contract whose signatory can be '
            .'removed is not enforceable.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('signer_label', PersonalDataEntry::IDENTITY,
                'Who signed, captured as text at the moment of signing so it still answers '
                .'the question after the account is gone.'),
            new PersonalDataEntry('affirmation', PersonalDataEntry::IDENTITY,
                'What they typed to sign. Their own name, in their own keystrokes — the '
                .'affirmative act ESIGN requires.'),
            new PersonalDataEntry('ip_address, user_agent', PersonalDataEntry::TECHNICAL,
                'Where the act came from. Attribution evidence, and the part a dispute '
                .'turns on.'),
            new PersonalDataEntry('signer_user_id, subject_user_id', PersonalDataEntry::IDENTITY,
                'Who signed and who it was about, while those accounts exist.'),
        ];
    }

    protected static function booted(): void
    {
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
