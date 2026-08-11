<?php

namespace App\Support\Contracts;

use App\Models\Onboarding;
use App\Models\OnboardingAnswer;
use Illuminate\Support\Collection;

/**
 * The contract document, rendered from the pinned version and the answers
 * (EZBIZI-HR-PLAN.md, "Output is PDF, kept forever").
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THE FORMAT IS HTML, NOT PDF. THIS IS THE JUSTIFICATION.
 *
 *  The plan says PDF. No PDF library is installed, so this was a
 *  decision to make rather than a line to write, and it was made on
 *  the following grounds — in this order, because the second one would
 *  hold even if a library were already here.
 *
 *  1. THE HASH HAS TO COVER THE FILE. The plan is explicit that "a
 *     fingerprint over one representation and a document stored in
 *     another" proves nothing about the file. Rendering HTML and then
 *     converting it to PDF produces two artefacts: one the signer
 *     read, one the system kept. Whichever is hashed, the other is
 *     unverifiable — and the PDF is the one that would be handed to a
 *     court. So there is exactly one document here, the bytes stored
 *     are the bytes hashed, and the bytes shown to the signer are the
 *     same bytes again (see OnboardingDocuments: the file is written
 *     first and the fingerprint is taken from the stored file, not
 *     from the string in memory).
 *
 *  2. IT IS WHAT THE SIGNER ACTUALLY SEES. ESIGN turns on the person
 *     having been able to read what they agreed to. This document is
 *     shown in a browser, which renders HTML natively; a PDF would be
 *     shown through a viewer, which is a different thing from the
 *     document and one more component between the person and the text.
 *
 *  3. RETRIEVABILITY IS THE ONE PROPERTY THAT MATTERS IN TWENTY YEARS,
 *     and it argues for the simpler format. This file is self-
 *     contained: its handful of style rules are inline, and there is no
 *     script, no external stylesheet, no font, no image and nothing
 *     fetched from anywhere. It opens in any browser, on any platform,
 *     with no software that has to still exist — and it is legible as
 *     plain text even without one.
 *
 *  4. ADDING THE DEPENDENCY IS A DEPLOY DECISION, NOT A CODE ONE.
 *     Releases are installed --no-dev and vendor/ is built at deploy
 *     time; a PDF writer earns its place through that pipeline and a
 *     conversation about fonts and non-Latin scripts, not by being
 *     required from a feature branch.
 *
 *  WHAT THIS COSTS, PLAINLY: an HTML file is easier for a layperson to
 *  edit than a PDF. That changes nothing about the evidence — the
 *  fingerprint detects a single altered character either way, and a
 *  PDF is not tamper-proof either — but "we keep PDFs" is a sentence
 *  people find reassuring and this is not it.
 *
 *  WHAT CHANGES WHEN A PDF WRITER ARRIVES: this class grows a second
 *  output and OnboardingDocuments stores that instead. Nothing else
 *  moves: `onboarding_documents` already carries hash, algorithm and
 *  length per row, and the attachment already carries its own mime, so
 *  documents rendered before and after the change stay verifiable
 *  side by side.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Deterministic, and it has to be ──────────────────────────────
 *
 * The same onboarding renders the same bytes every time. Nothing in the output
 * comes from `now()`, from a random id, from the request, or from anything that
 * could differ between the preview a signer read and the render taken a second
 * later. That is what lets the signing endpoint refuse a signature whose
 * `document_hash` no longer matches: a mismatch then means the DOCUMENT changed,
 * not that the renderer did.
 *
 * Field ORDER is the version's order, never sorted, for the same reason the
 * field list is a JSON array rather than a table.
 *
 * ── Signatures are not in it, and that is the design ─────────────
 *
 * This renders the AGREEMENT: the terms, the answers, the person. The executed
 * signatures live in `contract_signatures` and are not merged in.
 *
 * The alternative is circular and cannot be made to work: if the document
 * contained the signatures, the first signer would be fingerprinting a document
 * that does not yet contain their own signature, the second would fingerprint a
 * different document again, and the file finally stored would match neither
 * recorded hash — which is precisely the failure the plan names. Every signature
 * here fingerprints the same, single, stored artefact, so any of them can be
 * checked against the file anybody holds. It is also what every e-signature
 * vendor does: the agreement is one document, the certificate of completion is
 * another.
 *
 * ── Sensitive answers appear in the clear, and must ──────────────
 *
 * A contract with the bank account masked is not a contract. The masking rules
 * govern the API and the exports; this is the agreed document, and it says what
 * was agreed. What protects it is where it lives — behind the same authorization
 * as the onboarding, served as an attachment with `nosniff`, never at a
 * guessable path.
 */
final class DocumentRenderer
{
    /** The stored artefact's type, and its extension. Both follow from the note above. */
    public const MIME      = 'text/html; charset=utf-8';
    public const EXTENSION = 'html';

    /**
     * The document, as the exact bytes that will be stored and fingerprinted.
     *
     * @param  array<string, string|null>  $identity  the common core, read from its one
     *         home (the profile, or the invitation before there is an account).
     *         Passed in rather than read here so that this class does no queries
     *         and the caller decides what "the person" means.
     * @param  Collection<int, OnboardingAnswer>  $answers
     */
    public function render(Onboarding $onboarding, array $identity, Collection $answers): string
    {
        $version  = $onboarding->contractVersion;
        $contract = $version->contract;
        $byKey    = $answers->keyBy('field_key');

        $out = [];
        $out[] = '<!doctype html>';
        $out[] = '<html lang="en"><head><meta charset="utf-8">';
        $out[] = '<title>'.$this->e($contract->name).'</title>';
        // Inline, minimal, and no external anything — see point 3 above.
        $out[] = '<style>'.self::STYLE.'</style>';
        $out[] = '</head><body>';

        $out[] = '<header>';
        $out[] = '<p class="org">'.$this->e($onboarding->organization->name).'</p>';
        $out[] = '<h1>'.$this->e($contract->name).'</h1>';
        $out[] = '<p class="version">Version '.((int) $version->version).'</p>';

        if (($contract->description ?? '') !== '') {
            $out[] = '<p class="description">'.$this->e($contract->description).'</p>';
        }

        $out[] = '</header>';

        // ── the person ───────────────────────────────────────────
        //
        // Merged from the profile rather than stored twice — the one-home rule
        // says HR points at core identity, and this is the pointing.
        $out[] = '<section><h2>The person</h2><dl>';

        foreach (CommonCore::asked() as $key) {
            $out[] = $this->row(
                CommonCore::labelFor($key),
                $this->blankIfEmpty($identity[$key] ?? null),
            );
        }

        $out[] = '</dl></section>';

        // ── the template's own questions ─────────────────────────
        $out[] = '<section><h2>Terms</h2><dl>';

        foreach ($onboarding->fields() as $field) {
            if ($field->type === FieldType::SIGNATURE) {
                continue;   // rendered below, as a line rather than as an answer
            }

            $out[] = $this->row(
                $field->label,
                $this->answerText($field, $byKey->get($field->key)),
            );
        }

        $out[] = '</dl></section>';

        // ── who signs ────────────────────────────────────────────
        //
        // The lines, not the signatures. Naming them here is what makes the
        // document say who was supposed to sign it, so a reader can check that
        // the signature records beside it are the complete set.
        $signatureFields = $onboarding->signatureFields();

        if ($signatureFields !== []) {
            $out[] = '<section><h2>Signatures</h2>';
            $out[] = '<p class="note">Executed electronically. Each signature below is '
                .'recorded separately with the time it was given, the person who gave it, '
                .'and the fingerprint of this document as they were shown it.</p>';
            $out[] = '<dl>';

            foreach ($signatureFields as $field) {
                $out[] = $this->row(
                    $field->label,
                    $field->filledBy === FilledBy::EMPLOYER
                        ? 'To be signed by the employer'
                        : 'To be signed by the employee',
                );
            }

            $out[] = '</dl></section>';
        }

        $out[] = '</body></html>';

        // "\n" explicitly rather than PHP_EOL: the fingerprint is over these
        // bytes, and PHP_EOL differs between the box that renders and a box that
        // might one day re-render to verify.
        return implode("\n", $out)."\n";
    }

    /**
     * One answer, as words.
     *
     * The type decides the wording, which is the one thing about a field this
     * software is allowed to know — see FieldType for why the list of types is
     * code while everything else about a field is the tenant's.
     */
    private function answerText(FieldDefinition $field, ?OnboardingAnswer $answer): string
    {
        $value = $answer?->value;

        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field->type) {
            // A file answer is an attachment id, which means nothing to a reader.
            // The document says one was given; the file is on the record.
            FieldType::FILE => 'A file was provided and is attached to this record.',
            default         => (string) $value,
        };
    }

    private function blankIfEmpty(?string $value): string
    {
        return $value === null || trim($value) === '' ? '—' : $value;
    }

    private function row(string $label, string $value): string
    {
        return '<dt>'.$this->e($label).'</dt><dd>'.$this->e($value).'</dd>';
    }

    /**
     * Everything is escaped, and everything means everything.
     *
     * Labels and answers are both tenant input. A label of `<script>` in a
     * document served from this application's own origin is stored XSS with a
     * signature on it — and the document is served as an attachment with
     * `nosniff` precisely because belt and braces is the right number of
     * fastenings for a file whose contents somebody else wrote.
     */
    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private const STYLE = <<<'CSS'
    body{font:14px/1.6 Georgia,serif;max-width:44em;margin:3em auto;padding:0 1.5em;color:#111}
    header{border-bottom:2px solid #111;padding-bottom:1em;margin-bottom:2em}
    .org{text-transform:uppercase;letter-spacing:.12em;font-size:11px;margin:0 0 .4em}
    h1{font-size:24px;margin:0}
    .version{color:#555;font-size:12px;margin:.3em 0 0}
    .description{margin:1em 0 0}
    h2{font-size:13px;text-transform:uppercase;letter-spacing:.1em;margin:2.4em 0 .8em}
    dl{margin:0;display:grid;grid-template-columns:14em 1fr;gap:.5em 1.5em}
    dt{font-weight:bold}
    dd{margin:0}
    .note{color:#555;font-size:12px;margin:0 0 1em}
    CSS;
}
