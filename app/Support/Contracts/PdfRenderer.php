<?php

namespace App\Support\Contracts;

use App\Support\Files\HardenedPdf;
use App\Support\Files\PdfPaper;
use DateTimeInterface;
use DateTimeZone;
use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * A PDF COPY of a contract document — converted from the stored bytes, never
 * rendered a second time from the template.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THIS IS NOT A SECOND DOCUMENT. READ DocumentRenderer FIRST.
 *
 *  That class argues in four points that the FORMAT IS HTML, and three
 *  of them are untouched by this file, because this file does not
 *  produce an instrument — it produces a copy of one.
 *
 *  Point 1, the hash has to cover the file, stands EXACTLY as written.
 *  The HTML is still the only thing stored, still the only thing
 *  fingerprinted by `onboarding_documents.content_hash`, and still the
 *  only thing a signature points at. The failure it warns about is
 *  "rendering HTML and then converting it to PDF produces two
 *  artefacts: one the signer read, one the system kept" — and the way
 *  round that is not to avoid PDFs, it is to make sure the PDF is never
 *  a SECOND RENDER. So `render()` below takes the stored bytes as a
 *  string. It cannot reach an Onboarding, a ContractVersion or a
 *  FieldSet, because a class that could would be one refactor away from
 *  quietly producing the second artefact.
 *
 *  Point 2, it is what the signer actually sees, stands: the signing
 *  screen shows the HTML, and nothing here is ever put in front of
 *  somebody to sign. A PDF is downloaded AFTER the fact.
 *
 *  Point 3, retrievability, stands and is what makes this safe. The
 *  document is self-contained by design — inline styles, no script, no
 *  external stylesheet, no font, and nothing FETCHED from anywhere — so
 *  converting it needs no network, no assets and no resolution of
 *  anything outside the bytes it already is.
 *
 *  It is no longer true that it carries no image: a version may have a
 *  cover, and DocumentRenderer embeds that PNG as `data:image/png;base64`
 *  INSIDE the document. That is the same property stated more precisely
 *  rather than a weakening of it — the bytes travel with the file, so a
 *  copy opened in twenty years still has its cover and still fetches
 *  nothing. The converter's protocol rules permit that prefix and refuse
 *  every other scheme.
 *
 *  WHERE THOSE RULES LIVE CHANGED, and this is the pointer for whoever
 *  comes looking. The four locks, the `data:` exception and the
 *  fail-closed self-checks are in App\Support\Files\HardenedPdf, not in
 *  this file, because Inspection needed a PDF of its own and a second
 *  hardening implementation is how one of them silently falls behind on
 *  a security fix. Nothing about them changed in the move; the argument
 *  for them is stated there in full.
 *
 *  One thing DID change and it is stated here rather than left to be
 *  discovered: the allowlist now permits `data:image/jpeg;base64,` as
 *  well as PNG, because an inspection photograph comes off a camera and
 *  a camera does not emit PNG. It does not widen anything for a
 *  CONTRACT — DocumentRenderer still embeds a PNG cover and nothing
 *  else — and the schemes that carry a document rather than bytes,
 *  `svg+xml` and `text/html`, are still refused, with a test that runs
 *  the rules in both directions rather than counting them.
 *
 *  POINT 4 IS THE ONE THAT CHANGED, and it is rewritten in that class
 *  rather than contradicted here: the dependency is now installed and
 *  the deploy question is answered. The rest of this docblock is the
 *  answer.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why dompdf, in one paragraph ─────────────────────────────────
 *
 * dompdf is pure PHP with no binary, no headless browser and no service to
 * keep alive, which matters because `vendor/` is built at deploy time on the
 * same small instance that serves the app — wkhtmltopdf and anything
 * Chromium-based would have made the release a system-package problem and put
 * a browser engine on a box that has no business running one. Against mPDF,
 * the other pure-PHP candidate, the deciding difference is the SECURITY
 * SURFACE for this particular job: the input here is a document assembled from
 * tenant text, and dompdf's remote/local access is one explicit protocol
 * allow-list that can be emptied outright (below), whereas mPDF's fetching is
 * spread across several options and its feature set — annotations, barcodes,
 * scripting-adjacent directives — is surface this conversion has no use for.
 * dompdf is also the smaller thing to reason about: it renders exactly the
 * subset of HTML and CSS this document already is (a short inline stylesheet —
 * eleven rules, or fifteen once a body-driven document adds the agreement's own
 * four — a definition list and paragraphs), and everything it cannot do is
 * something the contract document never asks for. The cost, stated plainly:
 * dompdf's CSS support is old, its layout engine is not a browser's, and
 * complex non-Latin scripts need a font installed and configured. None of that
 * bites today because the source document is deliberately plain — and if a
 * tenant's wording ever needs a script the core fonts do not carry, that is a
 * font decision about this copy, not a change to the signed instrument.
 *
 * ── The copy says what it is, on its face and on every page ──────
 *
 * A derived copy that looks like the original is the thing that causes trouble
 * later, so this one cannot be mistaken for one. Two marks, and they are
 * different on purpose:
 *
 *   THE BANNER, spliced into the top of the document body. It says the file is
 *   a copy, names the document it derives from — contract, version, revision —
 *   and prints that document's fingerprint in full. This is PRESENTATION: it is
 *   what a reader sees first, and a hostile stylesheet could hide it. That was
 *   measured rather than assumed, and `withBanner()` records what was tried.
 *
 *   THE FOOTER, drawn onto the page canvas AFTER layout, in the bottom page
 *   margin of every page, with the page number. Not part of the HTML at all:
 *   it is stamped on, so nothing in the document — tenant wording, tenant
 *   labels, a stylesheet somebody grows later — can move it, hide it or push
 *   it off the page. It exists because a contract gets printed and page three
 *   gets separated from page one, and page three has to say what it is too.
 *
 *   IT IS ALSO THE GUARANTEE. The banner can be defeated; this cannot, because
 *   it is outside CSS entirely. If only one of the two marks survives, this is
 *   the one, and a test hides the banner deliberately to hold that.
 *
 * ── Deterministic, so that a copy has one fingerprint ────────────
 *
 * Two conversions of one stored document produce byte-identical PDFs. That is
 * not tidiness: the PDF carries a fingerprint of its own so a file somebody
 * emailed can be tied back to the document it derives from, and a converter
 * that stamped the current time into every copy would give one revision an
 * unbounded number of fingerprints.
 *
 * dompdf writes three things that would otherwise vary: a creation date, a
 * modification date and a file identifier seeded from `microtime()` and
 * `mt_rand()`. All three are pinned below — the dates to the moment the SOURCE
 * document was stored, the identifier to a digest of the source fingerprint.
 *
 * WHAT DETERMINISM DOES NOT SURVIVE, said plainly: dompdf writes its own
 * version into the PDF's Producer field, so upgrading the library changes the
 * bytes of a copy made from an unchanged document. That is a reason the tie
 * back to the signed document is the fingerprint RECORDED when the copy was
 * produced, rather than one recomputed years later by converting again — and
 * it is one more reason the instrument is the HTML and not this.
 */
final class PdfRenderer
{
    public function __construct(private readonly PdfPaper $paper) {}

    /** The derived artefact's type, and its extension. */
    public const MIME      = 'application/pdf';
    public const EXTENSION = 'pdf';

    /**
     * Convert one stored contract document into a PDF copy of itself.
     *
     * @param  string  $storedDocument  the bytes as they are on the disk — read back
     *         from storage by the caller, not re-rendered. This is the whole design;
     *         see the class docblock.
     * @param  string  $sourceFingerprint  `onboarding_documents.content_hash`, printed
     *         on the copy so the copy names the document it came from
     * @param  string  $sourceAlgorithm  which fingerprint that is
     * @param  DateTimeInterface  $sourceStoredAt  when the SOURCE document was stored.
     *         The copy is dated with it rather than with now(), so that two copies of
     *         one document are one file — see the class docblock.
     */
    public function render(
        string $storedDocument,
        string $organizationName,
        string $contractName,
        int $version,
        int $revision,
        string $sourceFingerprint,
        string $sourceAlgorithm,
        DateTimeInterface $sourceStoredAt,
        array $signatures = [],
        array $attachments = [],
        array $record = [],
    ): string {
        $dompdf = new Dompdf($this->options());

        $dompdf->loadHtml(
            $this->withBanner(
                $this->withAttachments(
                    $this->withRecord($this->withSignatures($storedDocument, $signatures), $record),
                    $attachments,
                ),
                $organizationName,
                $contractName,
                $version,
                $revision,
                $sourceFingerprint,
                $sourceAlgorithm,
            ),
            // Named rather than sniffed. DocumentRenderer emits UTF-8 and says so
            // in a meta tag, but the tag is inside bytes this method treats as
            // opaque, and a converter that guessed would mangle every accented
            // name in a Spanish contract on the day the tag moved.
            'UTF-8',
        );

        $dompdf->render();

        $this->stamp(
            $dompdf,
            $contractName,
            $version,
            $revision,
            $sourceFingerprint,
            $sourceAlgorithm,
            $sourceStoredAt,
        );

        return (string) $dompdf->output();
    }

    /**
     * THE CONVERTER FETCHES NOTHING, and the argument now lives elsewhere.
     *
     * The four locks, the `data:` exception and the fail-closed self-checks
     * moved to Support\Files\HardenedPdf when Inspection wanted a PDF of its
     * own. Nothing about them changed and nothing about them is contract-
     * specific: the reason they exist is that the document body is TENANT
     * INPUT, which is as true of an inspection answered on a roof as it is of
     * a contract template somebody wrote. A second copy of that configuration
     * would have been a second chance to get one of the locks wrong, and the
     * second copy is always the weaker one.
     *
     * The paper size is Support\Files\PdfPaper's, which the inspection report
     * reads too.
     */
    /**
     * What they uploaded, after the contract: each picture on its own page, any
     * other file named (Mia, 2026-10-02: Eve finished a technician's onboarding
     * and had no way to download the contract "that includes all the images,
     * signatures"). Added to the COPY, like the signatures, never to the stored
     * document each signer fingerprinted. Pictures go in as `data:` URIs, the
     * one source the hardened converter loads.
     *
     * @param  list<array{label: string, name: string, mime: string, data: ?string}>  $attachments
     */
    private function withAttachments(string $html, array $attachments): string
    {
        if ($attachments === []) {
            return $html;
        }

        $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<section class="attachments" style="page-break-before:always"><h2>Attachments</h2>';

        foreach ($attachments as $i => $file) {
            $out .= '<div style="'.($i > 0 && $file['data'] !== null ? 'page-break-before:always;' : '').'margin-bottom:16px">'
                .'<p><strong>'.$esc($file['label']).'</strong> &middot; '.$esc($file['name']).'</p>'
                .($file['data'] === null
                    ? '<p>This file is not a picture, so it is kept on the onboarding record rather than printed here.</p>'
                    : '<img src="data:'.$esc($file['mime']).';base64,'.$file['data'].'" alt="" style="max-width:100%;max-height:8.5in">')
                .'</div>';
        }

        $out .= '</section>';

        return str_contains($html, '</body>')
            ? str_replace('</body>', $out.'</body>', $html)
            : $html.$out;
    }

    /**
     * The signature blocks of the copy, filled from the signature records:
     * the drawn signature over the rule, the typed name, the date — A1
     * Tools' block (Jose, 2026-10-01). The stored document has them empty,
     * because it is what each signer fingerprinted before anybody signed.
     *
     * @param  array<string, array{name: string, date: string, png: ?string}>  $signatures
     */
    private function withSignatures(string $html, array $signatures): string
    {
        foreach ($signatures as $key => $block) {
            $k = preg_quote(htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '/');
            $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($block['png'] !== null) {
                $html = preg_replace(
                    '/<div class="sig-box" data-field="'.$k.'"><\/div>/',
                    '<div class="sig-box" data-field="'.$k.'"><img src="data:image/png;base64,'.$block['png'].'" alt=""></div>',
                    $html, 1,
                ) ?? $html;
            }
            foreach (['name', 'date'] as $part) {
                $html = preg_replace(
                    '/<span class="fill" data-field="'.$k.'" data-part="'.$part.'"><\/span>/',
                    '<span class="fill" data-field="'.$k.'" data-part="'.$part.'">'.$esc($block[$part]).'</span>',
                    $html, 1,
                ) ?? $html;
            }
        }

        return $html;
    }

    /**
     * Everything on file for the person that the contract's wording does not
     * state, after the contract (Jose, 2026-10-05: "it should also have the
     * section with everything on record for the employee, bank information,
     * ssn, etc"). Values in the clear: the download is behind the reveal
     * permission and audited as one.
     *
     * @param  list<array{label: string, value: string}>  $record
     */
    private function withRecord(string $html, array $record): string
    {
        if ($record === []) {
            return $html;
        }

        $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '<section class="record" style="page-break-before:always"><h2>Employee record</h2>'
            .'<table class="checklist"><tbody>';
        foreach ($record as $row) {
            $out .= '<tr><td style="width:40%"><strong>'.$esc($row['label']).'</strong></td>'
                .'<td>'.nl2br($esc($row['value'])).'</td></tr>';
        }
        $out .= '</tbody></table></section>';

        return str_contains($html, '</body>')
            ? str_replace('</body>', $out.'</body>', $html)
            : $html.$out;
    }

    /**
     * The record and the uploads on their own, for a contract signed
     * elsewhere and imported (its original PDF goes in front of this).
     *
     * @param  list<array{label: string, value: string}>  $record
     * @param  list<array{label: string, name: string, mime: string, data: ?string}>  $attachments
     */
    public function recordOnly(string $title, array $record, array $attachments): string
    {
        $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:Helvetica,Arial,sans-serif;font-size:9.5pt;color:#111}'
            .'table.checklist{width:100%;border-collapse:collapse}'
            .'table.checklist td{border:1px solid #999;padding:4pt 6pt;vertical-align:top}'
            .'</style></head><body><h1 style="font-size:14pt">'.$esc($title).'</h1></body></html>';
        $html = $this->withAttachments($this->withRecord($html, $record), $attachments);
        // The record follows the title on the same page.
        $html = str_replace('<section class="record" style="page-break-before:always">', '<section class="record">', $html);

        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The PDF of a form still being filled in: the "final result" view beside
     * the form (Jose, 2026-10-01). Same converter and paper as a signed copy,
     * stamped as a draft on every page so it can never pass for one.
     */
    public function draft(string $html, string $contractName): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            x: 34.0,
            y: $canvas->get_height() - 30.0,
            text: sprintf('DRAFT, nothing is signed yet | %s | page {PAGE_NUM} of {PAGE_COUNT}', $this->label($contractName)),
            font: $dompdf->getFontMetrics()->getFont('helvetica', 'normal'),
            size: 7.0,
            color: [0.46, 0.46, 0.46],
        );

        return (string) $dompdf->output();
    }

    private function options(): Options
    {
        return HardenedPdf::options($this->paper->get());
    }

    /**
     * The stored document, with the copy's banner at the top of its body.
     *
     * ── Spliced, and the splice is the only edit made to the bytes ──
     *
     * Everything else about the stored document goes through untouched. The
     * banner is inserted immediately after the opening `<body>` tag, which is
     * where a reader's eye starts, and styled with INLINE attributes.
     *
     * ── This banner is presentation, NOT a guarantee ─────────────
     *
     * The paragraph here used to claim that inline styling meant "the document's
     * own stylesheet cannot restyle it". That was false, and the correction is
     * worth writing down because the obvious fix is also false.
     *
     * An inline declaration outranks a stylesheet only FOR THE PROPERTY IT
     * DECLARES, and this box declares border, padding, margin, font and colour —
     * not `display`. So `div { display: none }`, which is barely an attack,
     * removed the entire notice while every one of those declarations still won.
     *
     * The obvious repair is to declare `display:block !important` inline. That
     * was tried and MEASURED, and it does not work: dompdf does not give an
     * inline `!important` priority over a stylesheet `!important`, so the banner
     * still disappeared. The `!important` was removed again rather than left in
     * as decoration implying a protection it does not provide. Chasing the rest
     * — zero height, off-page position, transforms — is a game with no end
     * against an engine whose cascade does not behave as the spec would suggest.
     *
     * So the honest division is:
     *
     *   - THE BANNER IS PRESENTATION. It is what a reader sees first in every
     *     real document, and it can be defeated by a hostile stylesheet.
     *   - THE FOOTER IS THE GUARANTEE. `stamp()` writes it onto the page canvas
     *     after layout, where CSS cannot reach it, on every page.
     *   - NEITHER IS REACHABLE TODAY, because `MergeTags::render` escapes the
     *     body and no `<style>` element survives that.
     *
     * test_the_footer_survives_a_document_that_tries_to_hide_the_banner holds
     * the second bullet, which is the one a copy's integrity rests on.
     *
     * A callback rather than a replacement string, because the banner contains
     * tenant text and `preg_replace` reads `$1` and `\0` in a replacement — an
     * organization called `$1 Chimney` would otherwise rewrite its own banner.
     *
     * No `<body>` at all is not a case DocumentRenderer produces, and the
     * fallback is still the right one rather than an exception: prepending puts
     * the notice first in the document, and an HTML5 parser hoists loose content
     * into the body. A copy that says what it is beats a download that fails.
     */
    private function withBanner(
        string $storedDocument,
        string $organizationName,
        string $contractName,
        int $version,
        int $revision,
        string $sourceFingerprint,
        string $sourceAlgorithm,
    ): string {
        $banner = $this->banner(
            $organizationName, $contractName, $version, $revision,
            $sourceFingerprint, $sourceAlgorithm,
        );

        // At the end, not the start (Jose, 2026-10-02: "the copy of a
        // document signed electronically thing should be at the end"): the
        // contract opens on its cover or its title, and the copy's statement
        // closes it. Every page still carries the footer stamp that says it
        // is a copy.
        $banner = '<div style="margin-top:24pt">'.$banner.'</div>';

        return str_contains($storedDocument, '</body>')
            ? str_replace('</body>', $banner.'</body>', $storedDocument)
            : $storedDocument.$banner;
    }

    /**
     * What the copy says on its face.
     *
     * Every tenant value is escaped. They are escaped in the source document
     * already, but this string is assembled here from values passed in as PHP
     * strings — a contract named `<script>` would be markup again if this method
     * forgot, and "the other class escapes it" is not a property this one can
     * check.
     */
    private function banner(
        string $organizationName,
        string $contractName,
        int $version,
        int $revision,
        string $sourceFingerprint,
        string $sourceAlgorithm,
    ): string {
        $organization = e($organizationName);

        // Presentation only. This box cannot be made to survive a hostile
        // stylesheet and no attempt is made here to pretend otherwise — see the
        // docblock above for what was tried and measured. The mark that always
        // survives is the canvas footer in `stamp()`.
        $box   = 'border:1.2pt solid #111;padding:9pt 11pt;margin:0 0 20pt;'
            .'font-family:Helvetica,Arial,sans-serif;font-size:8pt;line-height:1.5;color:#111';
        $title = 'font-size:9.5pt;font-weight:bold;letter-spacing:0.7pt;margin:0 0 6pt';
        $line  = 'margin:0 0 4pt';
        $mono  = 'font-family:Courier,monospace;font-size:7.5pt;margin:0 0 6pt;word-wrap:break-word';

        return '<div style="'.$box.'">'
            .'<div style="'.$title.'">COPY OF A DOCUMENT SIGNED ELECTRONICALLY</div>'
            .'<div style="'.$line.'">This file is a copy. The document that was signed is the '
                .'one held by '.$organization.', and this PDF was made from it — it is not '
                .'itself the thing that was agreed to. Where the two ever disagree, the signed '
                .'document is the one that counts.</div>'
            .'<div style="'.$line.'"><b>Document:</b> '.e($contractName)
                .' — version '.$version.', revision '.$revision.'</div>'
            .'<div style="'.$line.'"><b>Fingerprint of the signed document ('
                .e($sourceAlgorithm).'):</b></div>'
            .'<div style="'.$mono.'">'.e($sourceFingerprint).'</div>'
            .'<div style="margin:0">This copy has a fingerprint of its own. It was recorded '
                .'against whoever downloaded it, in '.$organization.'\'s audit log, which is '
                .'what ties a PDF somebody has in their hands back to the document above.</div>'
            .'</div>';
    }

    /**
     * The footer, and the metadata that makes two copies one file.
     *
     * Drawn on the CANVAS after layout rather than written into the HTML, so the
     * document cannot affect it — see the class docblock. It sits in the bottom
     * page margin, which is outside the box the document's content is laid out
     * in, so it cannot land on top of a clause.
     *
     * `page_text` puts it on EVERY page and resolves the two page-number
     * placeholders per page, which is the whole reason for stamping rather than
     * splicing a second element: a printed contract gets separated, and a loose
     * page has to say what it is and where it came from.
     */
    private function stamp(
        Dompdf $dompdf,
        string $contractName,
        int $version,
        int $revision,
        string $sourceFingerprint,
        string $sourceAlgorithm,
        DateTimeInterface $sourceStoredAt,
    ): void {
        $canvas = $dompdf->getCanvas();

        if (! $canvas instanceof CPDF) {
            /*
             * Unreachable while the backend is pinned to CPDF above, and it
             * refuses rather than skipping the footer. A copy that silently lost
             * the line saying it is a copy is the one outcome this class exists
             * to prevent, so it is not something to degrade gracefully into.
             */
            throw new RuntimeException(
                'The PDF converter produced a page this code cannot mark as a copy, so the '
                .'copy would go out looking like the original. Refusing to hand it over.'
            );
        }

        $font   = $dompdf->getFontMetrics()->getFont('helvetica', 'normal');
        $bottom = $canvas->get_height();

        /*
         * TWO LINES, and the split is arithmetic rather than taste.
         *
         * `page_text` draws a string; it does not wrap one, so a line too long
         * for the sheet runs off the edge and takes the fingerprint with it. A
         * fingerprint is 64 characters, the contract name can be another 48, and
         * the two together at a readable size do not fit across A4. So the
         * sentence and the name go on one line and the fingerprint has the next
         * to itself, where the longest case still lands well inside the narrowest
         * paper this converter accepts.
         *
         * ASCII separators, and no ellipsis. This text is written straight onto
         * the page rather than through the HTML parser, and the core fonts encode
         * Latin-1; anything outside it is folded by `label()` rather than left to
         * arrive as a box on somebody's contract.
         */
        $canvas->page_text(
            x: 34.0,
            // Measured from the TOP, because that is the canvas's origin. 34pt
            // and 24pt above the bottom edge sit inside the page's bottom margin,
            // which is where the document's own content stops — so the footer
            // cannot land on top of a clause.
            y: $bottom - 34.0,
            text: sprintf(
                'COPY of a document signed electronically | %s v%d r%d | page {PAGE_NUM} of {PAGE_COUNT}',
                $this->label($contractName), $version, $revision,
            ),
            font: $font,
            size: 6.0,
            color: [0.35, 0.35, 0.35],
        );

        $canvas->page_text(
            x: 34.0,
            y: $bottom - 24.0,
            text: 'Fingerprint of the signed document ('.$sourceAlgorithm.'): '.$sourceFingerprint,
            font: $font,
            size: 6.0,
            color: [0.35, 0.35, 0.35],
        );

        /*
         * The three values dompdf would otherwise vary per run.
         *
         * Written AFTER `render()` because `add_info` overwrites, and the adapter
         * sets its own CreationDate and ModDate from `date()` in its constructor.
         * The identifier is a digest of the source fingerprint rather than the
         * fingerprint itself: the PDF's /ID field is 32 hex characters, a digest
         * always is one, and a substring of a hash chosen for its length would
         * break the day `hash_algorithm` names something shorter.
         */
        $stamp = $sourceStoredAt instanceof \DateTimeImmutable
            ? $sourceStoredAt->setTimezone(new DateTimeZone('UTC'))
            : (new \DateTimeImmutable('@'.$sourceStoredAt->getTimestamp()))->setTimezone(new DateTimeZone('UTC'));

        $canvas->get_cpdf()->fileIdentifier = md5($sourceFingerprint);
        $canvas->add_info('CreationDate', 'D:'.$stamp->format('YmdHis').'+00\'00\'');
        $canvas->add_info('ModDate', 'D:'.$stamp->format('YmdHis').'+00\'00\'');
        $canvas->add_info('Title', $this->label($contractName).' (copy)');
        $canvas->add_info(
            'Subject',
            'Copy of a document signed electronically. Signed document '
            .$sourceAlgorithm.' '.$sourceFingerprint,
        );
    }

    /**
     * Tenant text, made safe to draw onto a page and to put in PDF metadata.
     *
     * Neither destination goes through an HTML parser, so escaping is the wrong
     * tool: what matters is that there are no line breaks to push the footer onto
     * a second line, no characters the core fonts cannot encode, and a length
     * that leaves room for the fingerprint beside it.
     */
    private function label(string $value): string
    {
        $flat = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        // Anything outside Latin-1 becomes '?' rather than a box or a mangled
        // pair of bytes. The name is in the banner in full, through the HTML
        // pipeline, which is where it can be rendered properly.
        $latin1 = mb_convert_encoding($flat, 'ISO-8859-1', 'UTF-8');

        $ascii = mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1');

        return mb_strlen($ascii) > 48 ? mb_substr($ascii, 0, 47).'-' : ($ascii ?: 'Contract');
    }
}
