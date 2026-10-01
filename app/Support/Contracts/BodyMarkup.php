<?php

namespace App\Support\Contracts;

/**
 * Declared markup a contract body may use, turned into HTML we wrote.
 *
 * The body is still escaped before this runs. Author HTML is already
 * `&lt;b&gt;`. What this recognises is punctuation that survives escaping:
 * headings, bold, lists, tables. The tags we emit are ours.
 *
 * Merge tags are left in place — they are substituted after this pass, so a
 * heading of `{{organization.name}}` still resolves.
 */
final class BodyMarkup
{
    public static function html(string $escaped): string
    {
        $lines = preg_split('/\R/u', $escaped);

        if ($lines === false) {
            return '<p>'.$escaped.'</p>';
        }

        $out   = [];
        $i     = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = $lines[$i];
            $trim = rtrim($line);

            if ($trim === '') {
                $i++;
                continue;
            }

            if (str_starts_with($trim, '|')) {
                [$html, $i] = self::table($lines, $i);
                $out[] = $html;
                continue;
            }

            if (preg_match('/^#{1,3} /', $trim) === 1) {
                $out[] = self::heading($trim);
                $i++;
                continue;
            }

            if (preg_match('/^[-•] /', $trim) === 1) {
                [$html, $i] = self::list($lines, $i);
                $out[] = $html;
                continue;
            }

            $block = [$trim];
            $i++;
            while ($i < $count) {
                $next = rtrim($lines[$i]);
                if ($next === '' || str_starts_with($next, '|')
                    || preg_match('/^#{1,3} /', $next) === 1
                    || preg_match('/^[-•] /', $next) === 1) {
                    break;
                }
                $block[] = $next;
                $i++;
            }

            $out[] = self::block($block);
        }

        return implode("\n", $out);
    }

    /**
     * A paragraph, or one of the two shapes A1 Tools' agreements set apart
     * (Jose, 2026-10-01: "look at how the pdf's were presented in a1 tools"):
     * a numbered section heading ("1. EMPLOYMENT DETAILS", or a line in
     * capitals), a lettered clause heading ("(e) Scheduled Pay Increases"),
     * and lines of "Label: value", whose labels are bold.
     *
     * @param  list<string>  $block
     */
    private static function block(array $block): string
    {
        if (count($block) === 1) {
            $plain = html_entity_decode($block[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $short = mb_strlen($plain) <= 80 && preg_match('/[.:;,]$/', $plain) === 0;

            if ($short && preg_match('/\p{Lu}/u', $plain) === 1 && $plain === mb_strtoupper($plain)) {
                return '<h2>'.self::inline($block[0]).'</h2>';
            }
            if ($short && preg_match('/^\([a-z]\) \S/', $plain) === 1) {
                return '<h3>'.self::inline($block[0]).'</h3>';
            }
        }

        // Bold labels only on a block of two or more such lines, A1's parties
        // and employment-details blocks; a sentence that happens to start with
        // "Note:" stays a sentence.
        $label = '/^([A-Z][A-Za-z]*(?: [A-Za-z]+){0,3}):( .*)$/';
        $fields = count(array_filter($block, fn (string $line) => preg_match($label, $line) === 1)) > 1;
        if ($fields) {
            $block = array_map(fn (string $line) => preg_replace($label, '<b>$1:</b>$2', $line), $block);
        }

        return '<p>'.self::inline(implode('<br>', $block)).'</p>';
    }

    private static function heading(string $line): string
    {
        $level = 2;
        if (str_starts_with($line, '### ')) {
            $level = 4;
            $line  = substr($line, 4);
        } elseif (str_starts_with($line, '## ')) {
            $level = 3;
            $line  = substr($line, 3);
        } else {
            $line = substr($line, 2);
        }

        return '<h'.$level.'>'.self::inline(trim($line)).'</h'.$level.'>';
    }

    /**
     * @param  string[]  $lines
     * @return array{0: string, 1: int}
     */
    private static function list(array $lines, int $i): array
    {
        $items = [];
        $count = count($lines);

        while ($i < $count) {
            $trim = rtrim($lines[$i]);
            if (preg_match('/^[-•] (.*)$/', $trim, $m) !== 1) {
                break;
            }
            $items[] = '<li>'.self::inline($m[1]).'</li>';
            $i++;
        }

        return ['<ul>'.implode('', $items).'</ul>', $i];
    }

    /**
     * @param  string[]  $lines
     * @return array{0: string, 1: int}
     */
    private static function table(array $lines, int $i): array
    {
        $rows  = [];
        $count = count($lines);

        while ($i < $count) {
            $trim = rtrim($lines[$i]);
            if (! str_starts_with($trim, '|')) {
                break;
            }
            $cells = array_map('trim', explode('|', trim($trim, '|')));
            if ($cells !== [] && preg_match('/^[-: ]+$/', $cells[0] ?? '') === 1) {
                $i++;
                continue;
            }
            $rows[] = $cells;
            $i++;
        }

        if ($rows === []) {
            return ['', $i];
        }

        $head = array_shift($rows);
        $html = '<table><thead><tr>';
        foreach ($head as $cell) {
            $html .= '<th>'.self::inline($cell).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>'.self::inline($cell).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return [$html, $i];
    }

    private static function inline(string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
    }
}
