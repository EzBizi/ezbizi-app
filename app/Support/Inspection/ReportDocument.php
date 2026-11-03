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
        $report->loadMissing(['customer.owningFranchise.company', 'job.franchise.company', 'submittedBy', 'version', 'attachments']);

        $screens = $report->version?->screens ?? [];
        $answers = $report->answers ?? [];

        // Only what this inspection asked. A masonry report must not print the
        // wood-stove, furnace and electric sections as blank findings, nor
        // count them in "N of M items" — the same conditions the run used.
        $screens = self::asked($screens, $answers);

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

                $type = (string) ($field['type'] ?? '');

                // Furniture is printed as furniture: a heading is a heading and
                // a note to the technician is a note. Neither has an answer, so
                // neither gets a label-and-value row.
                if ($type === FieldType::DIVIDER) {
                    $heading = trim((string) ($field['label'] ?? ''));
                    $body .= $heading === ''
                        ? '<hr class="d">'
                        : '<div class="dv">'.$this->text($heading).'</div>';

                    continue;
                }

                if ($type === FieldType::ALERT) {
                    $body .= $this->note(
                        (string) ($field['title'] ?? ''),
                        (string) ($field['body'] ?? ''),
                    );

                    continue;
                }

                $print = PrintAs::of($field);

                // Answered to steer the form, never printed. A customer does not
                // need to be told "masonry" eleven times.
                if ($print === PrintAs::IGNORE) {
                    continue;
                }

                $key = (string) ($field['key'] ?? '');
                $label = (string) ($field['label'] ?? $key);

                if ($print === PrintAs::ROOT) {
                    $body .= $this->finding($field, $answers, $photos);

                    continue;
                }

                $body .= '<div class="f"><div class="l">'.$this->text($label).'</div>'
                    .$this->answer($field, $answers[$key] ?? null, $photos)
                    .$this->citation($field)
                    .'</div>';
            }
        }

        if ($body === '') {
            $body = '<p class="empty">This inspection had no questions.</p>';
        }

        return $this->page($report, $this->summary($screens, $answers).$body.$this->beforeAfter($report).$this->table($screens, $answers));
    }

    /** Side by side, one row per pair; nothing at all when there are no pairs. */
    private function beforeAfter(InspectionReport $report): string
    {
        $rows = '';

        foreach (BeforeAfter::pairs($report) as $pair) {
            $rows .= '<tr><td colspan="2" class="l">'.$this->text($pair['label']).'</td></tr>'
                .'<tr><td style="width:50%">Before'.$this->photo($pair['before']).'</td>'
                .'<td style="width:50%">After'.$this->photo($pair['after']).'</td></tr>';
        }

        return $rows === '' ? '' : '<h2>Before &amp; after</h2><table class="ba" style="width:100%">'.$rows.'</table>';
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

        if ($type === FieldType::SERVICES) {
            return $this->work(is_array($value) ? $value : []);
        }

        if ($type === FieldType::CHECKBOX) {
            // A tick box says Yes or No, never "1" or an empty space. An
            // unticked box is a real answer and reads as one — a blank there
            // would read as a question nobody reached.
            return '<div class="v">'.($value === true ? 'Yes' : 'No').'</div>';
        }

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

        // A choice prints the option's words, as a finding does — "2" is a key,
        // "Level 2 — includes the exterior and flue" is the answer. A value no
        // option carries any more prints as stored rather than vanishing.
        if ($type === FieldType::CHOICE && $value !== null) {
            $words = [];
            foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
                if (($option = OptionSet::normalise($option)) !== null) {
                    $words[$option['value']] = $option['label'];
                }
            }
            $value = array_map(
                static fn ($v) => is_scalar($v) ? ($words[(string) $v] ?? (string) $v) : '',
                is_array($value) ? $value : [$value],
            );
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
     * One FINDING: a heading, and everything it gathered beneath it.
     *
     * The chosen option in WORDS — "not-ok" is a key for the engine, "Does not
     * meet standards" is what the customer is being told — coloured by that
     * option's variant so a failure is visible without reading, then the code
     * it is measured against, then whatever the option revealed: the
     * photograph, the explanation, the recommended work.
     *
     * Grouping is the whole point. Those three used to print as three unrelated
     * rows further down the page, and a reader had no way to tell which finding
     * a photograph belonged to.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     */
    private function finding(array $field, array $answers, $photos): string
    {
        $key = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? $key);
        $chosen = $this->chosenOption($field, $answers);

        $variant = $chosen['variant'] ?? OptionSet::DEFAULT_VARIANT;
        $said = $chosen['label'] ?? '';

        $inner = '';

        // What the option revealed, in the order the technician filled it in.
        foreach (OptionSet::revealedBy($field, $answers) as $nested) {
            if (! is_array($nested) || PrintAs::of($nested) === PrintAs::IGNORE) {
                continue;
            }

            $nestedKey = (string) ($nested['key'] ?? '');
            $nestedLabel = (string) ($nested['label'] ?? $nestedKey);

            $inner .= '<div class="f"><div class="l">'.$this->text($nestedLabel).'</div>'
                .$this->answer($nested, $answers[$nestedKey] ?? null, $photos)
                .'</div>';
        }

        return '<div class="finding '.$this->text($variant).'">'
            .'<div class="fh">'.self::badge($variant).$this->text($label).'</div>'
            .($said === '' ? '' : '<div class="fs">'.$this->text($said).'</div>')
            .$this->citation($field)
            .$inner
            .'</div>';
    }

    /**
     * The option this answer chose, normalised.
     *
     * Null-ish when the field is not a choice or nothing was answered, in which
     * case the finding still prints its heading — a question that was asked and
     * not answered is itself worth seeing on a report.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $answers
     * @return array{label?: string, variant?: string}
     */
    private function chosenOption(array $field, array $answers): array
    {
        if (($field['type'] ?? '') !== FieldType::CHOICE) {
            return [];
        }

        $answer = $answers[$field['key'] ?? ''] ?? null;
        if ($answer === null || $answer === '' || $answer === []) {
            return [];
        }

        $chosen = array_map(
            static fn ($v) => is_scalar($v) ? (string) $v : '',
            is_array($answer) ? $answer : [$answer],
        );

        $labels = [];
        $variant = OptionSet::DEFAULT_VARIANT;

        foreach ((is_array($field['options'] ?? null) ? $field['options'] : []) as $option) {
            $option = OptionSet::normalise($option);

            if ($option === null || ! in_array($option['value'], $chosen, true)) {
                continue;
            }

            $labels[] = $option['label'];

            // A multi-choice takes the WORST of what was chosen: one failing
            // item among three makes the finding a failure, and colouring it by
            // the first pick would hide that.
            if ($option['variant'] === 'fail') {
                $variant = 'fail';
            } elseif ($option['variant'] === 'pass' && $variant !== 'fail') {
                $variant = 'pass';
            } elseif ($variant === OptionSet::DEFAULT_VARIANT) {
                $variant = $option['variant'];
            }
        }

        return ['label' => implode(', ', $labels), 'variant' => $variant];
    }

    /**
     * How many findings did not meet standards.
     *
     * The single most useful line on a chimney report, and derivable only
     * because options carry a variant. Absent entirely when there are no
     * findings — an empty banner saying "0 of 0" is worse than no banner.
     *
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     */
    /**
     * The screens and fields this inspection showed, judged by the same
     * Condition::passes the run used — the printed report and the walk agree.
     *
     * @param  list<mixed>  $screens
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private static function asked(array $screens, array $answers): array
    {
        $out = [];

        foreach ($screens as $screen) {
            if (! is_array($screen) || ! Condition::passes($screen['visible_if'] ?? null, $answers)) {
                continue;
            }

            $screen['fields'] = array_values(array_filter(
                is_array($screen['fields'] ?? null) ? $screen['fields'] : [],
                static fn ($field) => is_array($field) && Condition::passes($field['visible_if'] ?? null, $answers),
            ));
            $out[] = $screen;
        }

        return $out;
    }

    /** PASS / FAIL / N/A, as A1's reports mark each finding. */
    private static function badge(string $variant): string
    {
        $word = ['pass' => 'PASS', 'fail' => 'FAIL', 'not_applicable' => 'N/A'][$variant] ?? null;

        return $word === null ? '' : '<span class="b '.$variant.'">'.$word.'</span>';
    }

    /**
     * Every finding in one table at the end, with the counts — the page a
     * customer reads first on A1's report ("Inspection Summary").
     *
     * @param  list<array<string, mixed>>  $screens
     * @param  array<string, mixed>  $answers
     */
    private function table(array $screens, array $answers): string
    {
        $rows = '';
        $count = ['pass' => 0, 'fail' => 0, 'not_applicable' => 0];

        foreach ($screens as $screen) {
            foreach ($screen['fields'] ?? [] as $field) {
                if (! is_array($field) || PrintAs::of($field) !== PrintAs::ROOT) {
                    continue;
                }

                $chosen = $this->chosenOption($field, $answers);
                $variant = (string) ($chosen['variant'] ?? '');
                if (isset($count[$variant])) {
                    $count[$variant]++;
                }

                $rows .= '<tr><td>'.$this->text((string) ($field['label'] ?? '')).'</td>'
                    .'<td>'.self::badge($variant).'</td>'
                    .'<td>'.$this->text((string) ($chosen['label'] ?? 'Not answered')).'</td></tr>';
            }
        }

        if ($rows === '') {
            return '';
        }

        // Its own page, as on A1's report, so the table never starts as a
        // stranded heading at the foot of the last finding.
        return '<h2 style="page-break-before:always">Inspection summary</h2>'
            .'<div class="cnt">Total: '.array_sum($count).' &nbsp; Pass: '.$count['pass']
            .' &nbsp; Fail: '.$count['fail'].' &nbsp; N/A: '.$count['not_applicable'].'</div>'
            .'<table class="st"><tr><th>Item</th><th>Status</th><th>Notes</th></tr>'.$rows.'</table>';
    }

    private function summary(array $screens, array $answers): string
    {
        $total = 0;
        $failed = 0;

        foreach ($screens as $screen) {
            foreach ((is_array($screen['fields'] ?? null) ? $screen['fields'] : []) as $field) {
                if (! is_array($field) || PrintAs::of($field) !== PrintAs::ROOT) {
                    continue;
                }

                $total++;

                if (($this->chosenOption($field, $answers)['variant'] ?? '') === 'fail') {
                    $failed++;
                }
            }
        }

        if ($total === 0) {
            return '';
        }

        return '<div class="sum'.($failed > 0 ? ' bad' : '').'">'
            .'Inspection result: '.($failed > 0 ? 'Fail' : 'Pass').'. '
            .$failed.' of '.$total.' item'.($total === 1 ? '' : 's')
            .' did not meet standards.</div>';
    }

    /**
     * The work this finding recommends, priced, with a line total.
     *
     * Printed from the SNAPSHOT stored in the answer — the name and price as
     * they were when the technician recommended it — never a fresh read of the
     * catalogue. A service renamed or re-priced afterwards must not change what
     * the customer was quoted, which is the same rule the job line follows.
     *
     * @param  list<mixed>  $picks
     */
    private function work(array $picks): string
    {
        $rows = '';
        $total = 0;

        foreach ($picks as $pick) {
            if (! is_array($pick)) {
                continue;
            }

            $qty = (int) ($pick['qty'] ?? 1);
            $unit = (int) ($pick['unit_price_minor'] ?? 0);
            $amount = $unit * $qty;
            $total += $amount;

            $rows .= '<tr><td>'.$this->text((string) ($pick['name'] ?? '')).'</td>'
                .'<td class="q">'.$qty.'</td>'
                .'<td class="m">'.$this->money($amount).'</td></tr>';
        }

        if ($rows === '') {
            return '<div class="v none">No work recommended</div>';
        }

        return '<table class="w">'.$rows
            .'<tr class="tot"><td>Total</td><td class="q"></td>'
            .'<td class="m">'.$this->money($total).'</td></tr>'
            .'</table>';
    }

    /**
     * Minor units as a decimal, with no currency symbol.
     *
     * The symbol is deliberately absent: the organization's currency lives on
     * the job, this document is not the place that decides it, and a hardcoded
     * dollar sign on a report for a franchise in Manchester is worse than a
     * bare number.
     */
    private function money(int $minor): string
    {
        return number_format($minor / 100, 2);
    }

    /**
     * A note the form showed the technician, printed where they saw it.
     *
     * On the report because it is part of what the inspection SAID — a customer
     * reading "Type B vents need one inch of clearance" beside a finding is
     * being told the same thing the technician was told, which is the point of
     * printing it at all.
     */
    private function note(string $title, string $bodyText): string
    {
        $title = trim($title);
        $bodyText = trim($bodyText);

        if ($title === '' && $bodyText === '') {
            return '';
        }

        return '<div class="note">'
            .($title === '' ? '' : '<span class="ct">'.$this->text($title).'</span>')
            .($bodyText === '' ? '' : ' '.nl2br($this->text($bodyText)))
            .'</div>';
    }

    /**
     * The building code this finding is measured against.
     *
     * Printed from the copy frozen INTO THE VERSION at publish, never looked up
     * live: a report says what the violation was on the day, and correcting the
     * library afterwards must not rewrite it. That is why the words are here at
     * all rather than an id.
     */
    private function citation(array $field): string
    {
        $code = $field['code'] ?? null;

        if (! is_array($code)) {
            return '';
        }

        $title = trim((string) ($code['title'] ?? $code['code'] ?? ''));
        $bodyText = trim((string) ($code['body'] ?? ''));

        if ($title === '' && $bodyText === '') {
            return '';
        }

        return '<div class="code">'
            .($title === '' ? '' : '<span class="ct">'.$this->text($title).'</span>')
            .($bodyText === '' ? '' : ' '.$this->text($bodyText))
            .'</div>';
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

        // The company the visit was for — the job's branch, else the client's
        // — heads and foots every page with its logo and phone, as A1's own
        // report does. Nothing found prints the page without them.
        $company = $report->job?->franchise?->company ?? $report->customer?->owningFranchise?->company;
        // One shared terms text for every branch (Jose 11-03) names each branch's own company.
        $body = str_replace('{{company_name}}', $this->text($company?->name ?? 'the company'), $body);
        $logo = $this->logo($company?->logo_path);
        $when = $report->job?->scheduled_for ?? $report->submitted_at;
        $top = implode(' | ', array_filter([
            $report->job?->number ? 'Job #: '.$report->job->number : null,
            $when ? 'Date: '.$when->format('F j, Y') : null,
            $report->submittedBy?->name ? 'Technician: '.$report->submittedBy->name : null,
        ]));

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
            .'.code{margin:3pt 0 0;padding:4pt 6pt;border-left:2pt solid #ccc;'
            .'font-size:9pt;color:#444}'
            .'.ct{font-weight:bold}'
            .'.dv{font-weight:bold;font-size:11pt;margin:12pt 0 4pt;'
            .'border-bottom:1px solid #ddd;padding-bottom:2pt}'
            .'hr.d{border:0;border-top:1px solid #ddd;margin:12pt 0 8pt}'
            .'.note{margin:6pt 0;padding:5pt 7pt;background:#f4f4f4;font-size:9pt}'
            .'table.w{width:100%;border-collapse:collapse;margin:3pt 0;font-size:10pt}'
            .'table.w td{padding:2pt 4pt;border-bottom:1px solid #eee}'
            .'table.w td.q{width:40pt;text-align:right}'
            .'table.w td.m{width:70pt;text-align:right}'
            .'table.w tr.tot td{font-weight:bold;border-bottom:0;border-top:1px solid #999}'
            .'.finding{margin:8pt 0;padding:6pt 8pt;border-left:3pt solid #bbb}'
            .'.finding.pass{border-left-color:#2e7d32}'
            .'.finding.fail{border-left-color:#c62828;background:#fdf6f6}'
            .'.finding.not_applicable{border-left-color:#999}'
            .'.fh{font-weight:bold;font-size:11pt}'
            .'.fs{margin:1pt 0 3pt}'
            .'.sum{margin:0 0 10pt;padding:6pt 8pt;background:#eef4ee;font-weight:bold}'
            .'.sum.bad{background:#fdecea}'
            .'.intro{margin:0 0 10pt}'
            .'img{max-width:220pt;max-height:220pt;margin:3pt 6pt 0 0}'
            .'@page{margin:78pt 40pt 60pt 40pt}'
            .'#ph{position:fixed;top:-62pt;left:0;right:0;height:48pt;border-bottom:1px solid #ccc;font-size:9pt;color:#444}'
            .'#ph img{max-height:36pt;max-width:160pt;margin:0}'
            .'#pf{position:fixed;bottom:-44pt;left:0;right:0;height:30pt;border-top:1px solid #ccc;font-size:8.5pt;color:#555}'
            .'#pf .pn:after{content:"Page " counter(page)}'
            .'.b{float:right;font-size:8pt;font-weight:bold;color:#fff;padding:1pt 5pt;margin-left:6pt}'
            .'.b.pass{background:#2e7d32}.b.fail{background:#c62828}.b.not_applicable{background:#888}'
            .'table.st{width:100%;border-collapse:collapse;font-size:9.5pt}'
            .'table.st th{text-align:left;border-bottom:1px solid #999;padding:3pt}'
            .'table.st td{border-bottom:1px solid #eee;padding:3pt}'
            .'table.st .b{float:none;margin:0}'
            .'.cnt{margin:0 0 6pt;padding:4pt 6pt;background:#f4f4f4;font-size:9.5pt}'
            .'</style></head><body>'
            .'<div id="ph"><table style="width:100%"><tr>'
            .'<td style="width:170pt">'.$logo.'</td>'
            .'<td style="text-align:right;vertical-align:middle">'.$this->text($top).'</td>'
            .'</tr></table></div>'
            .'<div id="pf"><table style="width:100%"><tr>'
            .'<td>'.$this->text(implode(' | ', array_filter([$company?->name, ...$this->contact($report, $company)]))).'</td>'
            .'<td class="pn" style="text-align:right"></td>'
            .'</tr></table></div>'
            .'<h1>'.$this->text($report->definition_name).'</h1>'
            .'<table class="h">'.$header.'</table>'
            .($intro === '' ? '' : '<div class="intro">'.nl2br($this->text($intro)).'</div>')
            .$body
            .'</body></html>';
    }

    /**
     * The branch's phone and email for the footer, as A1's reports print them.
     *
     * Jose (2026-11-02): they already live in Web Management. The company's
     * web location in the client's city first, then any of its locations with
     * a phone, then the branch's Google profile, then the company's own phone.
     *
     * @return list<string>
     */
    private function contact(InspectionReport $report, ?\App\Models\Company $company): array
    {
        if ($company === null) {
            return [];
        }

        $city = $report->customer?->city;
        $place = \App\Models\WebLocation::query()
            ->where('company_id', $company->getKey())
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('email'))
            ->when($city, fn ($q) => $q->orderByRaw('city = ? desc', [$city]))
            ->orderBy('sort_order')
            ->first();

        $franchiseId = $report->job?->franchise_id ?? $report->customer?->owning_franchise_id;
        $phone = $place?->phone
            ?: ($franchiseId === null ? null : \App\Models\BusinessLocation::query()
                ->where('franchise_id', $franchiseId)->whereNotNull('phone')->value('phone'))
            ?: $company->phone;

        return array_values(array_filter([$phone, $place?->email], fn ($v) => filled($v)));
    }

    /** The company logo as an inline PNG/JPEG, or nothing. */
    private function logo(?string $path): string
    {
        if ($path === null || $path === '') {
            return '';
        }

        $bytes = $this->files->contents($path);
        if ($bytes === null || $bytes === '') {
            return '';
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg'], true)
            ? '<img src="data:'.$mime.';base64,'.base64_encode($bytes).'" alt="">'
            : '';
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
