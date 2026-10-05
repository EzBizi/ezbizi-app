<?php

namespace App\Support\Hr;

use App\Support\Files\HardenedPdf;

/**
 * The W-4 and W-9, filled in and signed in the app (Jose, 2026-10-01: "this
 * is not something he should upload, it should be signed within the app as
 * well, check how a1tools did it").
 *
 * A1 Tools' way: the official IRS page as the picture, the answers printed in
 * its boxes, the drawn signature on its line. Page 1 of each form is kept as
 * an image in resources/hr/tax-forms (IRS forms are public); the positions
 * below are the form's own field boxes, in points from the top left of a US
 * Letter page, read from the fillable PDF's widgets.
 *
 * ponytail: one revision of each form. A new IRS revision means a new image
 * and a check of the positions.
 */
final class TaxForms
{
    public const W4 = 'w4';

    public const W9 = 'w9';

    public const KINDS = [self::W4, self::W9];

    /** [image, [key => [x, y, width, height]], signature [x, y], date [x, y]] */
    private const LAYOUT = [
        self::W4 => [
            'image' => 'fw4-2026.png',
            'text' => [
                'first_name' => [94.6, 94.0, 178.3, 14],
                'last_name' => [274.6, 94.0, 199.9, 14],
                'ssn' => [476.2, 94.0, 99.8, 14],
                'address' => [94.6, 118.0, 379.9, 14],
                'city_state_zip' => [94.6, 142.0, 379.9, 14],
                'children_amount' => [417.6, 480.0, 64.0, 12],
                'other_dependents_amount' => [417.6, 492.0, 64.0, 12],
                'credits_total' => [511.2, 516.0, 64.8, 12],
                'other_income' => [511.2, 552.0, 64.8, 12],
                'deductions' => [511.2, 588.0, 64.8, 12],
                'extra_withholding' => [511.2, 606.0, 64.8, 12],
                'employer' => [94.6, 726.0, 293.4, 30],
                'first_date' => [389.8, 744.0, 77.5, 12],
                'employer_ein' => [469.0, 744.0, 107.0, 12],
            ],
            'checks' => [
                'filing_status:single' => [115.2, 158.0],
                'filing_status:married' => [115.2, 170.0],
                'filing_status:head' => [115.2, 181.7],
                'multiple_jobs' => [564.0, 404.0],
                'exempt' => [564.0, 654.5],
            ],
            'signature' => [104.0, 680.0, 22],
            'date' => [484.0, 690.0],
        ],
        self::W9 => [
            'image' => 'fw9-2024-03.png',
            'text' => [
                'name' => [58.6, 118.0, 517.4, 14],
                'business_name' => [58.6, 142.0, 517.4, 14],
                'llc_code' => [417.6, 192.0, 28.8, 11],
                'other_classification' => [162.4, 228.0, 284.0, 12],
                'exempt_payee_code' => [543.6, 192.0, 32.4, 12],
                'fatca_code' => [500.6, 228.0, 75.4, 12],
                'address' => [58.6, 286.0, 329.4, 14],
                'city_state_zip' => [58.6, 310.0, 329.4, 14],
                'requester' => [389.8, 286.0, 186.2, 38],
                'account_numbers' => [58.6, 334.0, 517.4, 14],
                'ssn_1' => [417.6, 372.0, 43.2, 24],
                'ssn_2' => [475.2, 372.0, 28.8, 24],
                'ssn_3' => [518.4, 372.0, 57.6, 24],
                'ein_1' => [417.6, 420.0, 28.8, 24],
                'ein_2' => [460.8, 420.0, 100.8, 24],
            ],
            'checks' => [
                'classification:individual' => [73.0, 180.2],
                'classification:c_corporation' => [180.0, 180.2],
                'classification:s_corporation' => [252.0, 180.2],
                'classification:partnership' => [324.0, 180.2],
                'classification:trust_estate' => [388.8, 180.2],
                'classification:llc' => [73.0, 193.5],
                'classification:other' => [73.0, 230.0],
                'foreign_partners' => [440.6, 263.0],
            ],
            'signature' => [124.0, 574.0, 16],
            'date' => [406.0, 580.0],
            // One digit per box on the W-9's number lines.
            'comb' => ['ssn_1', 'ssn_2', 'ssn_3', 'ein_1', 'ein_2'],
        ],
    ];

    /** The width of one box on a comb line, in points. */
    private const COMB_CELL = 14.4;

    /**
     * The filled, signed form as a PDF.
     *
     * @param  array<string, string|bool|null>  $values  keys of LAYOUT text, and checks as key or key:option
     */
    public static function render(string $kind, array $values, ?string $signaturePng, string $date): string
    {
        $layout = self::LAYOUT[$kind];
        $image = base64_encode((string) file_get_contents(resource_path('hr/tax-forms/'.$layout['image'])));
        $e = fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $out = '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'@page{margin:0}body{margin:0}'
            .'.page{position:relative;width:612pt;height:792pt}'
            .'.bg{position:absolute;left:0;top:0;width:612pt;height:792pt}'
            .'.t{position:absolute;font-family:Helvetica,Arial,sans-serif;font-size:9pt;line-height:1.1;color:#000;overflow:hidden}'
            .'.x{position:absolute;font-family:Helvetica,Arial,sans-serif;font-size:9pt;font-weight:bold;line-height:1}'
            .'.sig{position:absolute;height:22pt}'
            .'</style></head><body><div class="page">'
            .'<img class="bg" src="data:image/png;base64,'.$image.'" alt="">';

        foreach ($layout['text'] as $key => [$x, $y, $w, $h]) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (in_array($key, $layout['comb'] ?? [], true)) {
                foreach (mb_str_split(preg_replace('/\D/', '', $value) ?? '') as $i => $digit) {
                    $out .= sprintf('<div class="t" style="left:%.1fpt;top:%.1fpt;width:%.1fpt;text-align:center">%s</div>',
                        $x + $i * self::COMB_CELL, $y + ($h - 9) / 2, self::COMB_CELL, $e($digit));
                }

                continue;
            }
            // A tall box (the employer block, the requester) wraps; a short
            // one sits centred on its line.
            $top = $h > 20 ? $y + 2 : $y + ($h - 9) / 2;
            $out .= sprintf('<div class="t" style="left:%.1fpt;top:%.1fpt;width:%.1fpt;max-height:%.1fpt">%s</div>',
                $x + 2, $top, $w - 4, $h, nl2br($e($value)));
        }

        foreach ($layout['checks'] as $check => [$x, $y]) {
            [$key, $option] = array_pad(explode(':', $check, 2), 2, null);
            $on = $option === null ? ! empty($values[$key]) : ($values[$key] ?? null) === $option;
            if ($on) {
                $out .= sprintf('<div class="x" style="left:%.1fpt;top:%.1fpt">X</div>', $x + 1, $y - 1);
            }
        }

        if ($signaturePng !== null) {
            [$x, $y, $h] = $layout['signature'];
            $out .= sprintf('<img class="sig" style="left:%.1fpt;top:%.1fpt;height:%dpt" src="data:image/png;base64,%s" alt="">',
                $x, $y, $h, base64_encode($signaturePng));
        }
        [$x, $y] = $layout['date'];
        $out .= sprintf('<div class="t" style="left:%.1fpt;top:%.1fpt">%s</div>', $x, $y, $e($date));

        return HardenedPdf::render($out.'</div></body></html>', 'letter');
    }

    /**
     * The same filled, signed form as a picture, from the same boxes: what
     * the contract's PDF prints as a page of its own (Jose, 2026-10-05: "the
     * w4-w9 signed pdf does not seem attached at all"). The copy can print a
     * picture and cannot merge a PDF, so the signed form is kept as both.
     */
    public static function renderPng(string $kind, array $values, ?string $signaturePng, string $date): string
    {
        $layout = self::LAYOUT[$kind];
        $page = imagecreatefrompng(resource_path('hr/tax-forms/'.$layout['image']));
        $img = imagecreatetruecolor(imagesx($page), imagesy($page));
        imagecopy($img, $page, 0, 0, 0, 0, imagesx($page), imagesy($page));
        imagedestroy($page);

        $scale = imagesx($img) / 612;              // points to pixels
        $size = 9 * $scale * 0.75;                  // GD sizes in points at 96 dpi
        $ink = imagecolorallocate($img, 0, 0, 0);
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $bold = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        $write = function (float $xPt, float $topPt, string $text, string $face = '') use ($img, $scale, $size, $ink, $font) {
            imagettftext($img, $size, 0, (int) round($xPt * $scale), (int) round(($topPt + 8) * $scale), $ink, $face ?: $font, $text);
        };

        foreach ($layout['text'] as $key => [$x, $y, $w, $h]) {
            $value = trim((string) ($values[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (in_array($key, $layout['comb'] ?? [], true)) {
                foreach (mb_str_split(preg_replace('/\D/', '', $value) ?? '') as $i => $digit) {
                    $write($x + $i * self::COMB_CELL + self::COMB_CELL / 2 - 2.5, $y + ($h - 9) / 2, $digit);
                }

                continue;
            }
            $top = $h > 20 ? $y + 2 : $y + ($h - 9) / 2;
            foreach (explode("\n", $value) as $n => $line) {
                $write($x + 2, $top + $n * 10, $line);
            }
        }

        foreach ($layout['checks'] as $check => [$x, $y]) {
            [$key, $option] = array_pad(explode(':', $check, 2), 2, null);
            $on = $option === null ? ! empty($values[$key]) : ($values[$key] ?? null) === $option;
            if ($on) {
                $write($x + 1, $y - 1, 'X', $bold);
            }
        }

        if ($signaturePng !== null && ($sig = @imagecreatefromstring($signaturePng)) !== false) {
            [$x, $y, $h] = $layout['signature'];
            $height = (int) round($h * $scale);
            $width = (int) round(imagesx($sig) * $height / max(1, imagesy($sig)));
            imagealphablending($img, true);
            imagecopyresampled($img, $sig, (int) round($x * $scale), (int) round($y * $scale), 0, 0,
                $width, $height, imagesx($sig), imagesy($sig));
            imagedestroy($sig);
        }
        [$x, $y] = $layout['date'];
        $write($x, $y, $date);

        ob_start();
        imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
