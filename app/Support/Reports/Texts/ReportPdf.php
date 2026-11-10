<?php

namespace App\Support\Reports\Texts;

use App\Support\Files\FileStore;
use App\Support\Files\HardenedPdf;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A report text (DailyTexts, a rule's WhatsApp message) as a formatted page:
 * the issuer's logo and name, the title and its date, every list a table with
 * its amounts right-aligned and the total in bold, and "EzBizi · generated"
 * with page numbers at the foot (Jose, 2026-11-09: "if ur going to make the
 * pdf at least give them some formatting").
 *
 * ── Read from the text, not from each report ─────────────────────
 *
 * Every report is written as WhatsApp lines, and the lines follow a handful
 * of shapes: "• *Name*: 3 completed · $5,897.69 sales", "• Name — 2 jobs",
 * "*Heading*" with "• Spent: $842.24 · Jobs: 0" under it, "*TOTAL: $99.00*".
 * One reader turns those shapes into rows and columns, so a new report gets a
 * table without a layout of its own. A line it cannot place is a paragraph.
 */
final class ReportPdf
{
    /** Emoji the PDF font has no glyph for. */
    private const EMOJI = '/[\x{1F000}-\x{1FFFF}\x{2700}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u';

    private const NUMBER = '[-+]?\$?[\d,]*\d(?:\.\d+)?%?';

    public static function render(string $body, Model $issuer, CarbonInterface $at): string
    {
        return HardenedPdf::render(self::html($body, $issuer, $at), 'letter', 'Page {PAGE_NUM} of {PAGE_COUNT}');
    }

    /** @param  Model  $issuer  the Company or Organization the report is for: its logo and name head the page */
    public static function html(string $body, Model $issuer, CarbonInterface $at): string
    {
        $lines = explode("\n", trim(preg_replace(self::EMOJI, '', str_replace("\r", '', $body)) ?? $body));
        $title = self::plain((string) array_shift($lines));
        $sub = [];
        while ($lines !== [] && trim($lines[0]) !== '') {
            $sub[] = self::inline(trim(array_shift($lines)));
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{margin:36px 40px 56px}'
            .'body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#1f2937}'
            .'.head{width:100%;border-bottom:2px solid #0b5cad;padding-bottom:8px;margin-bottom:14px}'
            .'.head td{vertical-align:middle}.logo img{max-height:44px;max-width:180px}'
            .'.org{font-size:13pt;font-weight:bold;color:#0b5cad;text-align:right}'
            .'h1{font-size:16pt;margin:0 0 2px}.sub{color:#6b7280;margin:0 0 2px}'
            .'h2{font-size:11pt;margin:16px 0 6px;color:#0b5cad;page-break-after:avoid}'
            .'p{margin:6px 0}hr{border:0;border-top:1px solid #d1d5db;margin:14px 0}'
            .'table.t{width:100%;border-collapse:collapse;margin:4px 0 10px}'
            .'.t th{text-align:left;font-size:8.5pt;color:#6b7280;border-bottom:1px solid #9ca3af;padding:4px 6px}'
            .'.t td{padding:4px 6px;border-bottom:1px solid #eef0f3}.t tr.z td{background:#f6f8fb}'
            .'.t .n{text-align:right;white-space:nowrap}.t tr.total td{font-weight:bold;border-top:1.5px solid #1f2937;border-bottom:0;background:#fff}'
            .'.foot{position:fixed;bottom:-36px;left:0;right:0;font-size:8pt;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:4px}'
            .'</style></head><body>'
            .'<div class="foot">EzBizi · generated '.e($at->format('M j, Y g:i A T')).'</div>'
            .'<table class="head"><tr><td class="logo">'.self::logo($issuer).'</td><td class="org">'.e((string) $issuer->getAttribute('name')).'</td></tr></table>'
            .'<h1>'.e($title).'</h1>'.implode('', array_map(fn ($s) => '<p class="sub">'.$s.'</p>', $sub))
            .self::blocks(self::merge(self::read($lines)))
            .'</body></html>';
    }

    /**
     * Each line as what it is: a heading, a row (name and metrics), a total, a
     * rule or a paragraph. Blank lines say nothing: a table's rows may be
     * spaced apart (Weekly reviews).
     *
     * @param  list<string>  $lines
     * @return list<array<string, mixed>>
     */
    private static function read(array $lines): array
    {
        $blocks = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^[—–-]{3,}$/u', $line)) {
                $blocks[] = ['hr'];
            } elseif (preg_match('/^\*?\s*(TOTAL|Total)\b\*?:?\s*(.*?)\*?$/u', $line, $m)) {
                $blocks[] = ['total', ucfirst(strtolower($m[1])), self::metrics(trim($m[2], ' ·*'))];
            } elseif (str_starts_with($line, '•')) {
                $blocks[] = ['row', ...self::row(trim(mb_substr($line, 1)))];
            } elseif (preg_match('/^\*([^*]+)\*\s+•\s*(.+)$/u', $line, $m)) {
                $blocks[] = ['row', trim($m[1]), self::metrics($m[2])];
            } elseif (preg_match('/^\*([^*]+)\*:?$/u', $line, $m)) {
                $blocks[] = ['heading', trim($m[1])];
            } else {
                $blocks[] = ['p', $line];
            }
        }

        return $blocks;
    }

    /** @return array{0: ?string, 1: list<array{0: string, 1: string, 2: string}>} name, metrics */
    private static function row(string $s): array
    {
        if (preg_match('/^\*([^*]+)\*:?\s*(.*)$/u', $s, $m)) {
            [$name, $rest] = [trim($m[1]), $m[2]];
            // "• *Combined losses: -$4,187.65*": the value was inside the bold.
            if ($rest === '' && preg_match('/^(.+?):\s+(.+)$/u', $name, $n)) {
                [$name, $rest] = [$n[1], $n[2]];
            }

            return [$name, self::metrics($rest)];
        }
        if (preg_match('/^(.+?) — (.+)$/u', $s, $m)) {
            return [trim($m[1]), self::metrics($m[2])];
        }

        return [null, self::metrics($s)];
    }

    /**
     * "3 completed · $5,897.69 sales", "Spent: $842.24", "spent $2,232.02":
     * each as [label, value, as written].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function metrics(string $rest): array
    {
        $out = [];
        foreach (preg_split('/\s+[·•]\s+|^\s*[·•]\s*/u', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $m) {
            $m = trim($m, ' *');
            $out[] = match (true) {
                (bool) preg_match('/^([^:]+?):\s*(.+)$/u', $m, $x) => [$x[1], $x[2], $m],
                (bool) preg_match('/^('.self::NUMBER.')\s+(.+)$/u', $m, $x) => [$x[2], $x[1], $m],
                (bool) preg_match('/^(.+?)\s+('.self::NUMBER.')$/u', $m, $x) => [$x[1], $x[2], $m],
                default => ['', $m, $m],
            };
        }

        return $out;
    }

    /**
     * A heading with only unnamed, labelled rows under it is one row named by
     * the heading (Marketing PPC's "*Chicago*" / "• Spent: … · Jobs: …").
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function merge(array $blocks): array
    {
        $out = [];
        for ($i = 0; $i < count($blocks); $i++) {
            $rows = [];
            for ($j = $i + 1; ($blocks[$i][0] === 'heading') && ($blocks[$j][0] ?? null) === 'row' && $blocks[$j][1] === null; $j++) {
                $rows[] = $blocks[$j];
            }
            $metrics = array_merge(...array_map(fn ($r) => $r[2], $rows ?: [[2 => []]]));
            if ($rows !== [] && $metrics !== [] && ! in_array('', array_column($metrics, 0), true)) {
                $out[] = ['row', $blocks[$i][1], $metrics, true];
                $i = $j - 1;

                continue;
            }
            $out[] = $blocks[$i];
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private static function blocks(array $blocks): string
    {
        $html = '';
        $rows = [];
        foreach ([...$blocks, ['end']] as $b) {
            // A heading's merged row and a plain row are different lists: each its own table (Net Loss).
            if ($rows !== [] && ($b[0] !== 'row' || ($rows[0][3] ?? false) !== ($b[3] ?? false))) {
                $html .= self::table($rows, $b[0] === 'total' ? $b : null);
                $rows = [];
                if ($b[0] === 'total') {
                    continue;
                }
            }
            if ($b[0] === 'row') {
                $rows[] = $b;

                continue;
            }
            $html .= match ($b[0]) {
                'heading' => '<h2>'.e($b[1]).'</h2>',
                'hr' => '<hr>',
                'p' => '<p>'.self::inline($b[1]).'</p>',
                'total' => '<p><b>'.e(trim($b[1].' '.implode(' · ', array_column($b[2], 2)))).'</b></p>',
                default => '',
            };
        }

        return $html;
    }

    /**
     * Rows as a table: a column per label when every metric has one, else per
     * position when every row has as many, else one column as written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $total
     */
    private static function table(array $rows, ?array $total): string
    {
        $all = array_merge(...array_map(fn ($r) => $r[2], $rows));
        $named = in_array(true, array_map(fn ($r) => $r[1] !== null, $rows), true);
        $key = fn (string $label) => preg_replace('/s$/', '', mb_strtolower(trim($label)));

        if ($all !== [] && ! in_array('', array_column($all, 0), true)) {
            $columns = [];
            foreach ($all as [$label]) {
                $k = $key($label);
                $columns[$k] = mb_strlen($label) > mb_strlen($columns[$k] ?? '') ? $label : $columns[$k];
            }
            $cells = function (array $metrics) use ($columns, $key) {
                $by = [];
                foreach ($metrics as [$label, $value, $as]) {
                    $by[$key($label)] = $value;
                }

                return array_map(fn ($k) => $by[$k] ?? '', array_keys($columns));
            };
            $head = array_values($columns);
        } else {
            $width = max(array_map(fn ($r) => count($r[2]), $rows));
            $even = count(array_unique(array_map(fn ($r) => count($r[2]), $rows))) === 1;
            $cells = $even
                ? fn (array $metrics) => array_pad(array_column($metrics, $width === 1 ? 2 : 1), $width, '')
                : fn (array $metrics) => [implode(' · ', array_column($metrics, 2))];
            $head = $even && $width > 1 ? array_map(fn ($m) => $m[0], $rows[0][2]) : array_fill(0, $even ? $width : 1, '');
        }

        $tr = function (?string $name, array $values, string $class) use ($named) {
            $html = '<tr class="'.$class.'">'.($named ? '<td>'.e((string) $name).'</td>' : '');
            foreach ($values as $v) {
                $html .= '<td'.(preg_match('/^(?:'.self::NUMBER.'|N\/A)(?:\s.*)?$/u', $v) ? ' class="n"' : '').'>'.e($v).'</td>';
            }

            return $html.'</tr>';
        };

        $html = '<table class="t">';
        if (implode('', $head) !== '') {
            $cap = fn (string $h) => mb_strtoupper(mb_substr($h, 0, 1)).mb_substr($h, 1);
            $html .= '<tr>'.($named ? '<th></th>' : '').implode('', array_map(fn ($h) => '<th class="n">'.e($cap($h)).'</th>', $head)).'</tr>';
        }
        foreach ($rows as $i => $r) {
            $html .= $tr($r[1], $cells($r[2]), $i % 2 === 1 ? 'z' : '');
        }
        if ($total !== null) {
            $values = $cells($total[2]);
            $fits = count($total[2]) > 0 && (count($values) === count($total[2]) || implode('', $values) !== '');
            $html .= $named || $fits
                ? $tr($total[1], $fits ? $values : array_pad([implode(' · ', array_column($total[2], 2))], count($values), ''), 'total')
                : '<tr class="total"><td colspan="'.count($values).'">'.e($total[1].' '.implode(' · ', array_column($total[2], 2))).'</td></tr>';
        }

        return $html.'</table>';
    }

    /** WhatsApp's *bold* and _italic_ as HTML, escaped. */
    private static function inline(string $text): string
    {
        return preg_replace(['/\*([^*\n]+)\*/u', '/(?<!\w)_([^_\n]+)_(?!\w)/u'], ['<b>$1</b>', '<i>$1</i>'], e($text)) ?? e($text);
    }

    private static function plain(string $text): string
    {
        return trim(str_replace(['*', '_'], '', $text));
    }

    /** The issuer's logo as an inline image; none when it has none or it cannot be read. */
    private static function logo(Model $issuer): string
    {
        $path = $issuer->getAttribute('logo_path');
        if (! is_string($path) || $path === '') {
            return '';
        }
        try {
            $disk = Storage::disk(app(FileStore::class)->defaultDisk());
            $mime = $disk->mimeType($path) ?: 'image/png';

            return in_array($mime, ['image/png', 'image/jpeg'], true)
                ? '<img src="data:'.$mime.';base64,'.base64_encode((string) $disk->get($path)).'">'
                : '';
        } catch (Throwable) {
            return '';
        }
    }
}
