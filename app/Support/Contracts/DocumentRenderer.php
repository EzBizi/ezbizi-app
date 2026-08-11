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
 *
 * ═══════════════════════════════════════════════════════════════════
 *  TWO DOCUMENTS COME OUT OF HERE. WHICH ONE IS DECIDED BY ONE
 *  THING: WHETHER THE VERSION HAS A BODY.
 *
 *  This matters more than an ordinary branch, because
 *  DocumentFingerprint is taken over whatever this produces and a
 *  signature is recorded against that fingerprint. So somebody
 *  reading a signature record years from now has to be able to say
 *  which of the two they are looking at.
 *
 *  BODY-DRIVEN — `contract_versions.body` is not null.
 *      The agreement, in the tenant's own words, with merge tags
 *      resolved (MergeTags). This is a contract: prose somebody wrote
 *      and somebody else read. The header identifying the document and
 *      the list of signature lines are added around it; the answers
 *      appear only where the wording puts them.
 *
 *  FORM DUMP — no body. THE FALLBACK, AND ONLY THAT.
 *      Headings and a definition list: the person, then every question
 *      with its answer. It is what this class produced before a body
 *      existed, it is what every version published before then still
 *      produces, and it is byte-for-byte what it was — so a document
 *      re-rendered from an old version is the same document.
 *
 *      It is a record of what was collected rather than a contract,
 *      and it is kept because a template with no wording yet must
 *      still produce something a person can read and sign, not an
 *      empty page.
 *
 *  ── Why the body does not also print the leftover answers ───
 *
 *  A body-driven document shows the fields its wording mentions and
 *  no others, and that is the decision rather than an omission. A
 *  contract says what it says. The answers a template collects but the
 *  wording never mentions — a National Insurance number for payroll,
 *  an emergency contact — are data the employer gathered, not terms
 *  the employee agreed to, and appending them to the signature page
 *  would have somebody signing under a data dump. They remain on the
 *  record, in the export, and in the §9 inventory, which is where
 *  collected data belongs.
 * ═══════════════════════════════════════════════════════════════════
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
        $version      = $onboarding->contractVersion;
        $contract     = $version->contract;
        $fields       = $version->fieldSet();
        $organization = (string) $onboarding->organization->name;
        $name         = (string) $contract->name;

        return $this->document(
            organizationName: $organization,
            contractName:     $name,
            description:      $contract->description,
            version:          (int) $version->version,
            fields:           $fields,
            body:             MergeTags::normalise($version->body),
            values: array_replace(
                $this->answerValues($fields, $identity, $answers),
                ContractFacts::values(
                    organizationName: $organization,
                    contractName:     $name,
                    version:          (int) $version->version,
                    /*
                     * The day the paperwork was drawn up, and never `now()`.
                     *
                     * This method is deterministic by contract (see the note
                     * above), and `started_at` is the only date in reach that
                     * was fixed before the signer read anything — so the
                     * preview they fingerprinted and the render taken to store
                     * the document carry the same date. See ContractFacts for
                     * why the SIGNING date cannot be in the document at all.
                     */
                    date: $onboarding->started_at?->toDateString() ?? '',
                ),
            ),
        );
    }

    /**
     * The same document, with sample values — what the editor is shown.
     *
     * It goes through `document()` and `MergeTags::render()`, which is the point
     * rather than an implementation detail: the preview a person approves and the
     * document a person signs are produced by one piece of code, so the preview
     * cannot quietly be a good-enough approximation of the thing with the
     * signature on it.
     *
     * Two things differ, and both are honest. The VALUES are samples rather than
     * answers, because nobody has answered yet. And the VERSION is the one this
     * draft would become rather than the one in force, because a draft is by
     * definition not yet published — so the header and `{{contract.version}}`
     * both name the next number. The merged wording is identical for identical
     * inputs, which is what the editor is actually approving.
     *
     * ── The one non-deterministic value in this class ────────────
     *
     * `now()` below. It is safe exactly here and nowhere else: nothing this
     * method returns is ever stored, attached or fingerprinted — the caller is
     * an editor screen — and a preview dated anything but today would be lying
     * about what the tenant is looking at. Every other date in this file is read
     * off a record.
     */
    public function sample(
        string $organizationName,
        string $contractName,
        ?string $description,
        int $version,
        FieldSet $fields,
        ?string $body,
    ): string {
        return $this->document(
            organizationName: $organizationName,
            contractName:     $contractName,
            description:      $description,
            version:          $version,
            fields:           $fields,
            body:             MergeTags::normalise($body),
            values: MergeTags::samples($fields, ContractFacts::values(
                organizationName: $organizationName,
                contractName:     $contractName,
                version:          $version,
                date:             now()->toDateString(),
            )),
        );
    }

    /**
     * The one place a contract document is assembled, from values rather than
     * from records.
     *
     * It takes strings and a field list, not an Onboarding, so that the signed
     * document and the editor's preview cannot drift: there is no second
     * assembler for either to be built by.
     *
     * @param  array<string, MergeValue>  $values  every key a tag may name, resolved
     */
    private function document(
        string $organizationName,
        string $contractName,
        ?string $description,
        int $version,
        FieldSet $fields,
        ?string $body,
        array $values,
    ): string {
        $out = [];
        $out[] = '<!doctype html>';
        $out[] = '<html lang="en"><head><meta charset="utf-8">';
        $out[] = '<title>'.$this->e($contractName).'</title>';
        // Inline, minimal, and no external anything — see point 3 above. The
        // agreement's rules are appended only when there is an agreement, so a
        // document with no body is byte-for-byte the one it always was.
        $out[] = '<style>'.self::STYLE.($body === null ? '' : "\n".self::AGREEMENT_STYLE).'</style>';
        $out[] = '</head><body>';

        $out[] = '<header>';
        $out[] = '<p class="org">'.$this->e($organizationName).'</p>';
        $out[] = '<h1>'.$this->e($contractName).'</h1>';
        $out[] = '<p class="version">Version '.$version.'</p>';

        if (($description ?? '') !== '') {
            $out[] = '<p class="description">'.$this->e($description).'</p>';
        }

        $out[] = '</header>';

        // Retired fields are out of both paths for the same reason they are out
        // of a new onboarding: they are not part of what this version asks.
        $live = array_values(array_filter(
            $fields->fields,
            fn (FieldDefinition $f) => ! $f->retired,
        ));

        if ($body === null) {
            $this->formDump($out, $live, $values);
        } else {
            // The agreement itself. `MergeTags::render` escapes the whole body
            // before it does anything else and puts the result in element
            // content only — this is that one place.
            $out[] = '<section class="agreement">';
            $out[] = MergeTags::render($body, $values);
            $out[] = '</section>';
        }

        // ── who signs ────────────────────────────────────────────
        //
        // The lines, not the signatures. Naming them here is what makes the
        // document say who was supposed to sign it, so a reader can check that
        // the signature records beside it are the complete set.
        //
        // Appended by this class in both paths rather than merged, so a body
        // cannot omit them: a contract whose author forgot to mention the
        // signature lines is still a contract that says who has to sign it.
        $signatureFields = array_values(array_filter(
            $live,
            fn (FieldDefinition $f) => $f->type === FieldType::SIGNATURE,
        ));

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
     * THE FALLBACK — the person, then every question with its answer.
     *
     * Reached only when the version has no body. Its output is unchanged from
     * before bodies existed, down to the bytes, so a document re-rendered from a
     * version published back then is the same document it was.
     *
     * A blank prints as an em dash here and as "[Label not provided]" in a body,
     * and the difference is the context rather than an inconsistency: in a
     * definition list the label is on the same line, so a dash under it is
     * unambiguous. In the middle of a sentence there is nothing to say which term
     * went missing. See MergeTags::resolveOne.
     *
     * @param  string[]  $out
     * @param  FieldDefinition[]  $live
     * @param  array<string, MergeValue>  $values
     */
    private function formDump(array &$out, array $live, array $values): void
    {
        // ── the person ───────────────────────────────────────────
        //
        // Merged from the profile rather than stored twice — the one-home rule
        // says HR points at core identity, and this is the pointing.
        $out[] = '<section><h2>The person</h2><dl>';

        foreach (CommonCore::asked() as $key) {
            $out[] = $this->row(
                CommonCore::labelFor($key),
                // `?? null` before the `?->`, not after: a key this map has never
                // heard of is an undefined index and warns, and a renderer that
                // emits a warning is one whose output depends on the error
                // handler. Every key here is in the map today; this is what keeps
                // that from being something a reader has to verify.
                ($values[$key] ?? null)?->value ?? '—',
            );
        }

        $out[] = '</dl></section>';

        // ── the template's own questions ─────────────────────────
        $out[] = '<section><h2>Terms</h2><dl>';

        foreach ($live as $field) {
            if ($field->type === FieldType::SIGNATURE) {
                continue;   // rendered below, as a line rather than as an answer
            }

            $out[] = $this->row(
                $field->label,
                ($values[$field->key] ?? null)?->value ?? '—',
            );
        }

        $out[] = '</dl></section>';
    }

    /**
     * What every tag may resolve to, for one onboarding: the common core and the
     * template's own answers.
     *
     * Both documents read this same map — the body merges it, the form dump lists
     * it — so an answer cannot appear one way in a contract with wording and
     * another way in one without.
     *
     * Retired fields are IN it. Their answers stay readable on the records that
     * carry them, which is the whole difference between retiring and deleting; it
     * is `document()` that leaves them out of what is printed.
     *
     * @param  array<string, string|null>  $identity
     * @param  Collection<int, OnboardingAnswer>  $answers
     * @return array<string, MergeValue>
     */
    private function answerValues(FieldSet $fields, array $identity, Collection $answers): array
    {
        $byKey  = $answers->keyBy('field_key');
        $values = [];

        foreach (CommonCore::asked() as $key) {
            $raw = $identity[$key] ?? null;

            $values[$key] = new MergeValue(
                CommonCore::labelFor($key),
                $raw === null || trim($raw) === '' ? null : $raw,
            );
        }

        foreach ($fields->fields as $field) {
            if ($field->key === '' || $field->type === FieldType::SIGNATURE) {
                // A signature is an act, not an answer. MergeTags refuses a tag
                // naming one at publish; leaving it out of the map is what makes
                // that refusal the only way it can be written.
                continue;
            }

            $values[$field->key] = new MergeValue(
                $field->label,
                $this->answerText($field, $byKey->get($field->key)),
            );
        }

        return $values;
    }

    /**
     * One answer, as words — or null when there is no answer.
     *
     * The type decides the wording, which is the one thing about a field this
     * software is allowed to know — see FieldType for why the list of types is
     * code while everything else about a field is the tenant's.
     *
     * Null rather than a dash, because the two documents spell a blank
     * differently and the decision belongs to whichever one is being written.
     */
    private function answerText(FieldDefinition $field, ?OnboardingAnswer $answer): ?string
    {
        $value = $answer?->value;

        if ($value === null || $value === '') {
            return null;
        }

        return match ($field->type) {
            // A file answer is an attachment id, which means nothing to a reader.
            // The document says one was given; the file is on the record.
            FieldType::FILE => 'A file was provided and is attached to this record.',
            default         => (string) $value,
        };
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

    /**
     * The wording's own rules, appended ONLY to a body-driven document.
     *
     * Kept apart from STYLE rather than merged into it so that a version with no
     * body still renders the exact bytes it rendered before bodies existed — see
     * formDump. A stylesheet grown by one rule is a different file, and "the same
     * record produces the same document" is a promise worth three lines to keep.
     *
     * No colour on the two markers. A contract is read on a screen, printed,
     * photocopied and faxed, and a gap that is only visible in colour is one that
     * disappears on the way — so the brackets and the wording carry the meaning
     * and the styling only reinforces it.
     */
    private const AGREEMENT_STYLE = <<<'CSS'
    .agreement{margin:2.4em 0}
    .agreement p{margin:0 0 1em}
    .unanswered{font-style:italic}
    .unresolved{font-family:monospace;font-weight:bold}
    CSS;
}
