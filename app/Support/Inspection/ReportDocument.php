<?php

namespace App\Support\Inspection;

use App\Models\Attachment;
use App\Models\InspectionReport;
use App\Support\Files\FileStore;

/**
 * A submitted inspection, as one self-contained HTML page.
 *
 * ── This IS a second render, and that is allowed here ────────────
 *
 * Contracts\PdfRenderer argues at length that a contract PDF must never be a
 * second render of anything — the bytes stored are the bytes hashed are the
 * bytes the signer read, and a re-render produces two artefacts of which only
 * one is verifiable. None of that binds an inspection report, and it is worth
 * saying why rather than leaving the next person to wonder whether this file
 * broke the rule.
 *
 * A contract is an INSTRUMENT: somebody signed it, a fingerprint covers it, and
 * the question the format answers is "what did this person agree to". A report
 * is a RECORD: nobody signs the page, no hash covers it, and the frozen thing
 * is the `answers` column, which the model refuses to let anyone change. So the
 * report has no stored representation this could disagree with. Rendering it
 * twice produces the same page twice, and if it ever does not, the difference
 * is in this file rather than in the evidence.
 *
 * What it must NOT become is a second place answers are frozen. It reads the
 * pinned version's screens and the report's own answers and it writes nothing.
 *
 * ── Self-contained, because the converter fetches nothing ────────
 *
 * Support\Files\HardenedPdf refuses http, https, file and phar outright. So the
 * styles are inline, there is no font, no stylesheet and no script, and a
 * photograph travels as `data:image/…;base64` INSIDE the page — part of its own
 * bytes. That is not a workaround for the locks; it is the property that makes
 * the locks free.
 */
final class ReportDocument
{
    /** Photographs above this are linked by name rather than embedded. */
    private const MAX_EMBEDDED_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly FileStore $files) {}

    public function html(InspectionReport $report): string
    {
        $report->loadMissing(['customer', 'job', 'submittedBy', 'version', 'attachments']);

        $screens = $report->version?->screens ?? [];
        $answers = $report->answers ?? [];

        // Keyed by id so a photo answer, which holds ids, can find its bytes
        // without a query per picture.
        $photos = $report->attachments->keyBy('id');

        $body = '';

        foreach ($screens as $screen) {
            if (! is_array($screen)) {
                continue;
            }

            $title = trim((string) ($screen['name'] ?? $screen['title'] ?? ''));
            if ($title !== '') {
                $body .= '<h2>'.$this->text($title).'</h2>';
            }

            foreach ($screen['fields'] ?? [] as $field) {
                if (! is_array($field)) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                $label = (string) ($field['label'] ?? $key);

                $body .= '<div class="f"><div class="l">'.$this->text($label).'</div>'
                    .$this->answer($field, $answers[$key] ?? null, $photos)
                    .'</div>';
            }
        }

        if ($body === '') {
            $body = '<p class="empty">This inspection had no questions.</p>';
        }

        return $this->page($report, $body);
    }

    /**
     * One answer, rendered by the field's declared type.
     *
     * An UNANSWERED field says so rather than printing nothing. A blank space on
     * a report reads as "there was nothing to report", and the two are different
     * findings — the second one is the reason the field was on the form.
     */
    private function answer(array $field, mixed $value, $photos): string
    {
        // A signature is a picture too — the pad rasterises to PNG and stores
        // the attachment id in the same one-element list a photo answer uses.
        // Falling through to the text branch would print a uuid where the
        // signature goes, which is the failure the drawn mark exists to fix.
        $type = $field['type'] ?? '';

        if ($type === FieldType::PHOTO || $type === FieldType::SIGNATURE) {
            $ids = is_array($value) ? $value : [];
            $shown = '';

            foreach ($ids as $id) {
                $attachment = $photos->get((string) $id);
                if ($attachment === null) {
                    continue;
                }
                $shown .= $this->photo($attachment);
            }

            return $shown === ''
                ? '<div class="v none">'
                    .($type === FieldType::SIGNATURE ? 'Not signed' : 'No photograph')
                    .'</div>'
                : '<div class="v photos">'.$shown.'</div>';
        }

        if (is_array($value)) {
            $value = implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        $text = trim((string) ($value ?? ''));

        return $text === ''
            ? '<div class="v none">Not answered</div>'
            : '<div class="v">'.nl2br($this->text($text)).'</div>';
    }

    /**
     * The picture, carried in the page rather than pointed at.
     *
     * A file that cannot be read is NAMED rather than skipped. A report that
     * silently drops a photograph looks like a report where none was taken, and
     * whoever is reading it has no way to tell the difference.
     */
    private function photo(Attachment $attachment): string
    {
        if ($attachment->size_bytes !== null
            && $attachment->size_bytes > self::MAX_EMBEDDED_BYTES) {
            return '<div class="v none">Photograph too large to include: '
                .$this->text($attachment->original_name).'</div>';
        }

        $bytes = $this->files->contents($attachment->path, $attachment->disk);

        if ($bytes === null || $bytes === '') {
            return '<div class="v none">Photograph unavailable: '
                .$this->text($attachment->original_name).'</div>';
        }

        // PNG and JPEG only, matching the two prefixes HardenedPdf permits. A
        // mime this does not know is not smuggled in under one that it does.
        $mime = in_array($attachment->mime, ['image/png', 'image/jpeg'], true)
            ? $attachment->mime
            : null;

        if ($mime === null) {
            return '<div class="v none">Photograph in an unsupported format: '
                .$this->text($attachment->original_name).'</div>';
        }

        return '<img src="data:'.$mime.';base64,'.base64_encode($bytes).'" alt="">';
    }

    /**
     * ponytail: `sans-serif` below, never a named font.
     *
     * It maps to dompdf's built-in Helvetica, which needs no font file. Naming
     * `DejaVu Sans` instead makes dompdf parse a 700KB TTF and exhaust a 128M
     * process on the cmap table — measured here, not guessed.
     *
     * The ceiling is Latin-1: accented Spanish names come out right, non-Latin
     * scripts do not. Bundle and register a Unicode font here on the day a
     * tenant needs one.
     */
    private function page(InspectionReport $report, string $body): string
    {
        $facts = array_filter([
            'Client' => $report->customer?->name,
            'Job' => $report->job?->number,
            'Inspected by' => $report->submittedBy?->name,
            'Submitted' => $report->submitted_at?->toDayDateTimeString(),
        ]);

        $header = '';
        foreach ($facts as $label => $fact) {
            $header .= '<tr><td class="k">'.$this->text($label).'</td>'
                .'<td>'.$this->text((string) $fact).'</td></tr>';
        }

        $intro = trim((string) ($report->version?->intro ?? ''));

        return '<!doctype html><html><head><meta charset="utf-8">'
            .'<title>'.$this->text($report->definition_name).'</title>'
            .'<style>'
            .'body{font-family:sans-serif;font-size:11pt;color:#111}'
            .'h1{font-size:16pt;margin:0 0 4pt}'
            .'h2{font-size:12pt;margin:14pt 0 6pt;border-bottom:1px solid #ccc;padding-bottom:2pt}'
            .'table.h{margin:0 0 10pt;font-size:10pt}'
            .'td{padding:1pt 10pt 1pt 0;vertical-align:top}'
            .'td.k{color:#555}'
            .'.f{margin:0 0 8pt}'
            .'.l{font-weight:bold;font-size:10pt}'
            .'.v{margin-top:1pt}'
            .'.none{color:#777;font-style:italic}'
            .'.intro{margin:0 0 10pt}'
            .'img{max-width:220pt;max-height:220pt;margin:3pt 6pt 0 0}'
            .'</style></head><body>'
            .'<h1>'.$this->text($report->definition_name).'</h1>'
            .'<table class="h">'.$header.'</table>'
            .($intro === '' ? '' : '<div class="intro">'.nl2br($this->text($intro)).'</div>')
            .$body
            .'</body></html>';
    }

    /**
     * Everything a tenant wrote goes through here.
     *
     * Field labels, answers and file names are all typed by somebody, and this
     * page is converted by a library that parses HTML. Escaping is what keeps a
     * label of `<style>` from being a stylesheet.
     */
    private function text(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
