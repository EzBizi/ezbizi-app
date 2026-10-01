<?php

namespace App\Support\Contracts;

use App\Models\Onboarding;

/**
 * The words a person reads when they sign (§ESIGN/UETA).
 *
 * ── Why these sentences are the SERVER's ─────────────────────────
 *
 * `contract_signatures` stores the consent and intent statements as text rather
 * than as booleans, and its migration says why: "A `consented_to_electronic
 * BOOLEAN` records that somebody ticked something; it cannot say what."
 *
 * That is only true if the stored text is the text that was displayed. If the
 * client sent the statements, a record could say somebody agreed to anything at
 * all — the evidence would be whatever the thing being given evidence about
 * chose to claim. So the words are composed here, published to the client
 * through the onboarding response so it can display exactly them, and written to
 * the record from here again at the moment of signing. One source, read twice.
 *
 * ── Why they are composed rather than constant ───────────────────
 *
 * They name the organization and the document. "I agree to sign this document
 * electronically" is weaker evidence than a sentence that says which document
 * and whose — a person reading their own record back should be able to see what
 * they agreed to without having to be told what "this" referred to.
 *
 * None of it is tenant-configurable, and that is deliberate rather than an
 * oversight of §2.1. These sentences are what makes the signature count; a
 * tenant who could rewrite them could weaken their own evidence, and the first
 * anybody would notice is in front of a tribunal. The organization's NAME is
 * theirs; the assurances are the platform's.
 *
 * ── Revising the wording later is safe ───────────────────────────
 *
 * Because each record keeps the sentence it was signed under. Changing these
 * strings changes what the next signer reads and nothing about what any earlier
 * one agreed to, which is the property the text columns were chosen for.
 */
final class Ceremony
{
    public function __construct(
        private readonly Onboarding $onboarding,
    ) {}

    /**
     * Consent to transact electronically — a separate agreement from the
     * contract, which is why the record times it separately.
     *
     * The last sentence is a statement about this system that has to stay true:
     * the document is downloadable from the record for as long as the record
     * exists, through the ordinary attachment endpoint. A paper copy is between
     * the person and their employer, and the wording says so rather than
     * promising something this software cannot deliver.
     */
    public function consent(): string
    {
        return sprintf(
            'I agree to enter into this agreement with %s electronically, and to receive it '
            .'and any notices about it in electronic form rather than on paper. I can open '
            .'and download a copy of the signed document from my record at any time, and I '
            .'can ask %s for a paper copy.',
            $this->organizationName(),
            $this->organizationName(),
        );
    }

    /**
     * Intent — the words directly above the control they use.
     *
     * It names the document, the version, and the act. "By typing my name" is
     * accurate today; when a drawn signature can be captured, this sentence
     * changes with the control, and every record signed before it keeps the
     * sentence it was signed under.
     */
    public function intent(): string
    {
        $version = $this->onboarding->contractVersion;

        return sprintf(
            // Drawn as well as typed since 2026-10-01 (Jose: "the actual
            // signature, like hand drawn signature").
            'By drawing my signature and typing my name below I am signing "%s" (version %d) as it was shown to me '
            .'just now, and I intend this to be my signature and to be bound by it. I '
            .'understand that a fingerprint of the exact document I have read is being '
            .'recorded, so that it can be shown later to be the one I signed.',
            (string) $version?->contract?->name,
            (int) $version?->version,
        );
    }

    private function organizationName(): string
    {
        return (string) $this->onboarding->organization?->name;
    }
}
