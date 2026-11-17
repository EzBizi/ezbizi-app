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
 *     script, no external stylesheet, no font and NOTHING FETCHED FROM
 *     ANYWHERE. It opens in any browser, on any platform, with no
 *     software that has to still exist — and it is legible as plain
 *     text even without one.
 *
 *     A cover page carries one image, and it does not weaken any of
 *     that: the PNG is base64 inside a `data:` URI, so it is part of
 *     the file's own bytes and part of what the fingerprint covers.
 *     There is still nothing to fetch and still nothing that has to
 *     exist elsewhere. See `cover()` for why a `<img src="/branding/…">`
 *     was refused — it would have broken this point AND made the first
 *     page of a signed document change when somebody rebranded.
 *
 *  4. THE DEPENDENCY IS INSTALLED, AND IT DOES NOT WRITE THE
 *     INSTRUMENT. This point used to say that adding a PDF writer was
 *     a deploy decision rather than a code one, and left it open.
 *     It is decided: `dompdf/dompdf` is required, it is pure PHP so it
 *     needs no binary on the instance, and PdfRenderer is what uses
 *     it — see that class for the argument, including why mPDF was not
 *     chosen and what the library cannot do.
 *
 *     What it produces is a COPY, and the three points above are why.
 *     The PDF is converted from the STORED BYTES of the document this
 *     class rendered, never from a second run of this class — two
 *     independent renders is precisely the "two artefacts" failure
 *     point 1 describes, and it would be that failure whether or not a
 *     library were installed. So: one instrument, in one format, with
 *     one fingerprint over it, and a derived copy that says on its face
 *     that it is one.
 *
 *  WHAT THIS COSTS, PLAINLY: an HTML file is easier for a layperson to
 *  edit than a PDF. That changes nothing about the evidence — the
 *  fingerprint detects a single altered character either way, and a
 *  PDF is not tamper-proof either — but "we keep PDFs" is a sentence
 *  people find reassuring and this is not it. A tenant who needs one to
 *  hand to somebody can now download it; what they get is a copy,
 *  labelled as one, carrying the fingerprint of the document it came
 *  from.
 *
 *  WHAT DID NOT CHANGE WHEN THE PDF WRITER ARRIVED, because an earlier
 *  draft of this note predicted otherwise and was wrong: this class did
 *  NOT grow a second output, and OnboardingDocuments does NOT store the
 *  PDF instead. Storing the PDF would move the stored bytes away from
 *  the hashed bytes, which is the one thing point 1 forbids. The stored
 *  artefact is still this file's output, `onboarding_documents` still
 *  fingerprints exactly that, and the copy is produced on demand from
 *  it and kept by nobody.
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
 *      existed, and it is what every version published before then
 *      still produces.
 *
 *      It is a record of what was collected rather than a contract,
 *      and it is kept because a template with no wording yet must
 *      still produce something a person can read and sign, not an
 *      empty page.
 *
 *  ── "BYTE-FOR-BYTE WHAT IT WAS" IS NO LONGER CLAIMED ────────
 *
 *  This section used to promise that a form dump was byte-identical
 *  to the one this class produced before bodies existed. That promise
 *  is withdrawn, deliberately and on the owner's instruction: the
 *  header no longer prints `Version N` or the template's description
 *  (see `document()`), which changes the bytes of every document this
 *  class renders, of both kinds.
 *
 *  IT COSTS NOTHING THAT WAS BEING RELIED ON, and it is worth saying
 *  exactly why rather than asserting it. Nothing re-renders a stored
 *  document and compares: the instrument is the bytes on the disk,
 *  `onboarding_documents.content_hash` is the hash OF THOSE BYTES
 *  (OnboardingDocuments writes the file and fingerprints what it
 *  reads back), and every signature points at that recorded hash. A
 *  document signed last week is unchanged, still verifiable against
 *  the file, and still convertible to a PDF copy — because all three
 *  read the stored artefact and none of them runs this class again.
 *
 *  What determinism is FOR is narrower and is untouched: the same
 *  onboarding, rendered twice by the SAME build, produces the same
 *  bytes — which is what lets the signing endpoint tell "the document
 *  changed" from "the renderer is non-deterministic" between showing
 *  somebody a document and recording their signature on it.
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
    /** @param  list<string>  $highlight  keys marked for the live preview only */
    public function render(Onboarding $onboarding, array $identity, Collection $answers, array $highlight = []): string
    {
        $version      = $onboarding->contractVersion;
        $contract     = $version->contract;
        $fields       = $version->fieldSet();
        $employer     = $onboarding->employer();
        $organization = $employer['name'];
        $name         = (string) $contract->name;

        return $this->document(
            organizationName: $organization,
            contractName:     $name,
            fields:           $fields,
            body:             MergeTags::normalise($version->body),
            // Off the VERSION, which is the only place a cover may come from —
            // see ContractVersion::hasCover. Null when there is none, which is
            // the ordinary contract.
            coverPng:         $version->coverPng(),
            values: array_replace(
                $this->answerValues($fields, $identity, $answers),
                ContractFacts::values(
                    organizationName:    $organization,
                    organizationAddress: $employer['address'],
                    contractName:        $name,
                    version:             (int) $version->version,
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
            highlight: $highlight,
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
        int $version,
        FieldSet $fields,
        ?string $body,
        ?string $coverPng = null,
        string $organizationAddress = '',
    ): string {
        return $this->document(
            organizationName: $organizationName,
            contractName:     $contractName,
            fields:           $fields,
            body:             MergeTags::normalise($body),
            // The DRAFT's cover, resolved by the caller from the same request
            // that carries the draft — a preview is of a version that does not
            // exist yet, so there is nothing stored to read it off.
            coverPng:         $coverPng,
            values: MergeTags::samples($fields, ContractFacts::values(
                organizationName:    $organizationName,
                organizationAddress: $organizationAddress,
                contractName:        $contractName,
                version:             $version,
                date:                now()->toDateString(),
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
        FieldSet $fields,
        ?string $body,
        ?string $coverPng,
        array $values,
        array $highlight = [],
    ): string {
        $out = [];
        $out[] = '<!doctype html>';
        $out[] = '<html lang="en"><head><meta charset="utf-8">';
        $out[] = '<title>'.e($contractName).'</title>';
        /*
         * Inline, minimal, and no external anything — see point 3 above.
         *
         * Each optional block of rules is appended only when the thing it
         * styles is present, so a document never carries a rule for something
         * it does not contain. That used to be justified as keeping a
         * body-less document byte-identical to the one rendered before bodies
         * existed; that claim is retired rather than quietly left standing,
         * because removing the version line and the description from the
         * header (see below) changed every document's bytes on purpose. What
         * the discipline is worth now is smaller and still worth three lines:
         * a stylesheet that describes exactly what is in the file.
         */
        $out[] = '<style>'.self::STYLE
            .($coverPng === null ? '' : "\n".self::COVER_STYLE)
            .($body === null ? '' : "\n".self::AGREEMENT_STYLE)
            .'</style>';
        $out[] = '</head><body>';

        if ($coverPng !== null) {
            $this->cover($out, $organizationName, $contractName, $coverPng);
        }

        /*
         * ── WHAT THE HEADER DOES NOT SAY, AND WHY ────────────────
         *
         * Two lines used to sit here and both have been taken out. The
         * owner's words: *"a contract should not say version 4 or anyone,
         * those 2 fields should not show in the contract"*.
         *
         * `Version N` is a fact about the TEMPLATE, not a term of the
         * agreement. Nobody agrees to a version number, and printing one at
         * the top of an employment contract makes the document read like a
         * release of software.
         *
         * The DESCRIPTION is worse, because it is not even about the
         * document: `contracts.description` is the note that tells whoever is
         * starting an onboarding which template to pick — "Anyone", "Drivers
         * only", "use this one for seasonal staff". It is addressed to the
         * employer, and it was being printed above the terms as though it
         * were addressed to the person signing.
         *
         * ── NOTHING IS LOST EVIDENTIALLY, and nothing is added back ─
         *
         * The version is still recorded everywhere it is EVIDENCE rather
         * than decoration: `contract_signatures.contract_version_id` names it
         * on every signature, `onboardings` pins it, PdfRenderer's canvas
         * footer prints it — with the fingerprint — on every page of every
         * copy, and a body may still merge `{{contract.version}}` if a tenant
         * genuinely wants it in their own wording. So this is a change to
         * what the document SAYS, not to what the record PROVES, and neither
         * line is to be reintroduced somewhere quieter.
         *
         * The description is still shown where it belongs: on the contracts
         * list and the detail payload, which is how somebody picks the right
         * template. It is only the document it leaves.
         */
        $out[] = '<header>';
        $out[] = '<p class="org">'.e($organizationName).'</p>';
        $out[] = '<h1>'.e($contractName).'</h1>';
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
            $out[] = MergeTags::render($body, $values, $highlight);
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
            // A body ends in its own "Acknowledgment & Signatures" clause, so
            // the blocks follow it with no second heading, as A1 Tools' did.
            $out[] = $body === null ? '<section><h2>Signatures</h2>' : '<section class="signatures">';
            $out[] = '<p class="note">Executed electronically. Each signature below is '
                .'recorded separately with the time it was given, the person who gave it, '
                .'and the fingerprint of this document as they were shown it.</p>';

            // A1 Tools' signature block: a space for the drawn signature over
            // a rule, then the printed name and the date. Empty here, because
            // this is what is fingerprinted; the PDF copy fills each block
            // from its signature record (PdfRenderer).
            foreach ($signatureFields as $field) {
                $key = e($field->key);
                $out[] = '<div class="sig"><h3>'.e($field->label).'</h3>'
                    .'<div class="sig-box" data-field="'.$key.'"></div>'
                    .'<div class="sig-rule">Signature</div>'
                    .'<p class="sig-line"><b>Print Name:</b> <span class="fill" data-field="'.$key.'" data-part="name"></span></p>'
                    .'<p class="sig-line"><b>Date:</b> <span class="fill" data-field="'.$key.'" data-part="date"></span></p>'
                    .'</div>';
            }

            $out[] = '</section>';
        }

        $out[] = '</body></html>';

        // "\n" explicitly rather than PHP_EOL: the fingerprint is over these
        // bytes, and PHP_EOL differs between the box that renders and a box that
        // might one day re-render to verify.
        return implode("\n", $out)."\n";
    }

    /**
     * The cover page — the business's mark, the business, the agreement.
     *
     * ── The bytes are IN the file, and they have to be ───────────
     *
     * A `<img src="/branding/organization">` would be shorter and it would
     * break the one property this document is built on: point 3 above says the
     * file is self-contained — "no script, no external stylesheet, no font, no
     * image and nothing fetched from anywhere" — because it has to open in
     * twenty years with no server to ask. A cover fetched at render time is
     * also a cover that changes when the logo changes, which is the failure
     * ContractVersion::hasCover describes at length.
     *
     * So the PNG is base64 in a `data:` URI, inside the bytes that get
     * fingerprinted. The signer's copy contains the mark they were shown.
     *
     * ── `alt` is empty ON PURPOSE ────────────────────────────────
     *
     * The business's name is the very next line, in text. An `alt` repeating
     * it makes a screen reader say it twice, which is the standard failure of
     * captioning a logo that sits beside its own name. The image is decorative
     * HERE because the text carries the meaning — and if the image fails to
     * load, the cover still says whose agreement this is.
     *
     * ── It is a real page, not a big heading ─────────────────────
     *
     * `page-break-after` in COVER_STYLE, which dompdf honours, so the PDF copy
     * starts the agreement on page two. In a browser it is a full viewport
     * height, so scrolling past it feels like turning a page rather than
     * scrolling past a picture.
     *
     * @param  string[]  $out
     * @param  string  $png  raw PNG bytes, already established as a PNG of one of
     *         Branding's two shapes when the version was published
     */
    private function cover(array &$out, string $organizationName, string $contractName, string $png): void
    {
        $out[] = '<section class="cover">';
        $out[] = '<img class="cover-mark" src="data:image/png;base64,'
            .base64_encode($png).'" alt="">';
        $out[] = '<p class="cover-org">'.e($organizationName).'</p>';
        // A paragraph rather than a second <h1>: the header below carries the
        // document's one heading, and an outline with two top-level headings is
        // a document a screen reader cannot summarise.
        $out[] = '<p class="cover-title">'.e($contractName).'</p>';
        $out[] = '</section>';
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
            if ($field->key === '' || ! FieldType::merges($field->type)) {
                /*
                 * Asked of FieldType rather than compared against SIGNATURE here,
                 * and that is not tidying. This map is the other half of what
                 * MergeTags::resolvable() promises: every tag it offers has a
                 * value waiting in here. Two spellings of one question keep that
                 * promise only for as long as they happen to agree.
                 *
                 * They agree today, because signature is the only type in the
                 * list — a signature is an act, not an answer, and MergeTags
                 * refuses a tag naming one at publish. The day a type leaves the
                 * list, `=== SIGNATURE` would go on excluding a field that
                 * resolvable() has started offering and refusalFor() has stopped
                 * refusing. What that prints is not a blank: the key is absent
                 * from the map entirely, so MergeTags::resolveOne writes the tag
                 * back inside an `unresolved` span — while the editor's preview,
                 * which builds its values from resolvable(), shows a tidy
                 * "[Label]" in the same spot. The preview and the signed document
                 * would disagree, which is the one property the preview exists
                 * to have.
                 */
                continue;
            }

            $answer = $byKey->get($field->key);
            $values[$field->key] = new MergeValue(
                $field->label,
                $this->answerText($field, $answer),
                html: $field->type === FieldType::CHECKLIST
                    ? ChecklistTable::html($field, $answer?->value)
                    : null,
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
            FieldType::FILE      => 'A file was provided and is attached to this record.',
            FieldType::CHECKLIST => ChecklistTable::summary($field, $value),
            FieldType::RATE_STEPS => RateSteps::summary($value),
            default              => (string) $value,
        };
    }

    /**
     * Everything is escaped (Laravel's e(): ENT_QUOTES | ENT_SUBSTITUTE, UTF-8),
     * and everything means everything.
     *
     * Labels and answers are both tenant input. A label of `<script>` in a
     * document served from this application's own origin is stored XSS with a
     * signature on it — and the document is served as an attachment with
     * `nosniff` precisely because belt and braces is the right number of
     * fastenings for a file whose contents somebody else wrote.
     */
    private function row(string $label, string $value): string
    {
        return '<dt>'.e($label).'</dt><dd>'.e($value).'</dd>';
    }

    private const STYLE = <<<'CSS'
    @page{margin:54pt 48pt}
    body{font-family:Roboto,"Helvetica Neue",Helvetica,Arial,sans-serif;font-size:9.5pt;line-height:1.45;color:#000;max-width:516pt;margin:24pt auto;padding:0 16pt}
    @media print{body{max-width:none;margin:0;padding:0}}
    header{margin:0 0 12pt}
    .org{color:#757575;font-size:7pt;letter-spacing:.08em;text-transform:uppercase;margin:0 0 2pt}
    h1{font-size:18pt;font-weight:bold;margin:0}
    h2{font-size:12pt;font-weight:bold;margin:10pt 0 4pt}
    h3{font-size:10.5pt;font-weight:bold;margin:10pt 0 4pt}
    dl{margin:0}
    dt{font-weight:bold;margin:4pt 0 0}
    dd{margin:0 0 3pt}
    .note{color:#757575;font-size:8pt;margin:0 0 6pt}
    .sig{margin:14pt 0 0;page-break-inside:avoid}
    .sig-box{height:44pt;width:280pt}
    .sig-box img{height:40pt}
    .sig-rule{width:280pt;border-top:0.7pt solid #000;font-size:8pt;padding-top:1pt;margin:0 0 8pt}
    .sig-line{margin:0 0 3pt;font-size:9pt}
    .sig-line .fill{display:inline-block;min-width:200pt;border-bottom:0.7pt solid #000}
    mark{background:#fff1a8;padding:0 1pt}
    CSS;

    /**
     * The cover page's rules, appended ONLY to a document that has one.
     *
     * `max-width:60%` on the mark rather than a pixel size, because Branding
     * publishes two shapes — 512x512 and 1024x256 — and a width that suited one
     * would make the other either postage-stamp small or wider than the sheet.
     * `height:auto` keeps whichever one it is undistorted.
     *
     * `page-break-after:always` is what makes this a PAGE. dompdf reads it, so
     * the PDF copy opens on the cover and starts the agreement on the next
     * sheet; a browser ignores it on screen and honours it when somebody prints.
     * `min-height:100vh` is the screen half of the same idea — dompdf does not
     * support viewport units and ignores it, which is the right way round: the
     * rule that matters on paper is the one the converter understands.
     */
    private const COVER_STYLE = <<<'CSS'
    .cover{page-break-after:always;min-height:100vh;display:flex;flex-direction:column;
    justify-content:center;align-items:center;text-align:center;margin:0 0 3em}
    .cover-mark{max-width:60%;height:auto;margin:0 0 2.5em}
    .cover-org{text-transform:uppercase;letter-spacing:.14em;font-size:12px;margin:0 0 1em}
    .cover-title{font-size:30px;line-height:1.3;margin:0}
    CSS;

    /**
     * The wording's own rules, appended ONLY to a body-driven document.
     *
     * Kept apart from STYLE rather than merged into it so a document carries no
     * rule for something it does not contain — see the note in `document()`,
     * which also records that the older and stronger claim this made (that a
     * body-less document was byte-identical to the one rendered before bodies
     * existed) no longer holds and why.
     *
     * No colour on the two markers. A contract is read on a screen, printed,
     * photocopied and faxed, and a gap that is only visible in colour is one that
     * disappears on the way — so the brackets and the wording carry the meaning
     * and the styling only reinforces it.
     */
    private const AGREEMENT_STYLE = <<<'CSS'
    .agreement{margin:0}
    .agreement p{margin:0 0 6pt;text-align:justify}
    .agreement h2{font-size:12pt;margin:10pt 0 4pt}
    .agreement h3{font-size:10.5pt;margin:10pt 0 4pt}
    .agreement h4{font-size:9.5pt;margin:8pt 0 3pt}
    .agreement ul{margin:0 0 6pt;padding-left:12pt}
    .agreement li{margin:0 0 2pt}
    .agreement table{width:100%;border-collapse:collapse;margin:6pt 0;font-size:9pt}
    .agreement th,.agreement td{border:0.5pt solid #9e9e9e;padding:3pt 4pt;text-align:left;vertical-align:top}
    .agreement th{font-weight:bold}
    .unanswered{font-style:italic}
    .unresolved{font-family:monospace;font-weight:bold}
    CSS;
}
