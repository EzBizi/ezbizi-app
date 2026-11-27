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

    /** The payroll provider's direct deposit authorization (Jose, 2026-10-06). */
    public const DIRECT_DEPOSIT = 'direct_deposit';

    /** USCIS Form I-9, edition 01/20/25: Section 1 by the new hire. */
    public const I9 = 'i9';

    /** Section 2 of the same I-9, drawn by the employer onto the signed page. */
    public const I9_SECTION_2 = 'i9_section2';

    /** What the new hire fills and signs. */
    public const KINDS = [self::W4, self::W9, self::DIRECT_DEPOSIT, self::I9];

    /** The onboarding question each form's signed page is filed under. */
    public const ANSWER_KEYS = [
        self::W4 => 'tax_form', self::W9 => 'tax_form',
        self::DIRECT_DEPOSIT => 'direct_deposit_form', self::I9 => 'i9_form',
    ];

    public const LABELS = [
        self::W4 => 'W-4', self::W9 => 'W-9',
        self::DIRECT_DEPOSIT => 'Direct deposit authorization', self::I9 => 'I-9',
    ];

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
        // The form Jose sent (Intuit QuickBooks Payroll, ver. 061812). No
        // fillable fields: the positions are its printed lines.
        self::DIRECT_DEPOSIT => [
            'image' => 'direct-deposit.png',
            'text' => [
                'routing' => [218.0, 253.0, 358.0, 12],
                'account' => [125.0, 274.0, 450.0, 12],
                'amount' => [335.0, 296.0, 240.0, 12],
                'bank_info' => [60.0, 500.0, 490.0, 40],
                'company' => [117.0, 607.0, 372.0, 12],
                'employee_id' => [441.0, 714.0, 134.0, 12],
                'print_name' => [95.0, 736.0, 260.0, 12],
            ],
            'checks' => [
                'account_type:checking' => [151.0, 235.0],
                'account_type:savings' => [248.0, 235.0],
            ],
            'signature' => [146.0, 706.0, 20],
            'date' => [392.0, 739.0],
        ],
        // USCIS I-9 page 1, edition 01/20/25, from the fillable PDF's widgets.
        self::I9 => [
            'image' => 'i9-2025-01.png',
            'text' => [
                'last_name' => [42.7, 172.0, 155.7, 15],
                'first_name' => [204.2, 172.0, 137.7, 15],
                'middle_initial' => [348.4, 172.0, 65.4, 15],
                'other_last_names' => [420.4, 172.0, 155.7, 15],
                'address' => [41.9, 198.5, 186.2, 13.5],
                'apt' => [233.6, 198.5, 65.7, 13.5],
                'city' => [305.9, 198.5, 149.4, 13.5],
                'state' => [461.2, 198.5, 41.2, 13.5],
                'zip' => [509.9, 198.5, 65.4, 13.5],
                'date_of_birth' => [41.8, 224.5, 99.1, 13.8],
                'ssn' => [150.0, 224.8, 105.0, 13.8],
                'email' => [264.2, 224.5, 186.0, 13.8],
                'phone' => [456.1, 224.4, 119.2, 14],
                'lpr_number' => [390.6, 282.6, 184.9, 11.2],
                'work_until' => [390.2, 295.0, 59.3, 12.9],
                'uscis_number' => [180.6, 336.6, 82.8, 11.2],
                'i94_number' => [276.6, 336.6, 106.8, 11.2],
                'passport' => [396.6, 336.6, 178.8, 11.2],
            ],
            'checks' => [
                'status:citizen' => [182.5, 260.0],
                'status:noncitizen_national' => [182.5, 272.0],
                'status:permanent_resident' => [182.5, 284.0],
                'status:authorized_alien' => [182.7, 296.3],
            ],
            'signature' => [130.0, 354.0, 16],
            'date' => [375.0, 360.0],
            // Nine boxes across the SSN field.
            'comb_cells' => ['ssn' => 11.67],
        ],
        self::I9_SECTION_2 => [
            'image' => null,
            'text' => [
                'a_title' => [126.8, 432.7, 136.4, 17],
                'a_authority' => [126.8, 450.7, 136.4, 17],
                'a_number' => [126.8, 468.8, 136.4, 17],
                'a_expires' => [126.8, 486.8, 136.4, 16.4],
                'b_title' => [276.8, 432.3, 142.4, 17],
                'b_authority' => [277.5, 450.2, 142.2, 17],
                'b_number' => [276.8, 468.1, 142.2, 17],
                'b_expires' => [276.8, 486.1, 142.2, 17],
                'c_title' => [420.8, 432.5, 154.7, 17],
                'c_authority' => [420.8, 450.2, 154.7, 17],
                'c_number' => [420.8, 468.1, 154.7, 17],
                'c_expires' => [420.8, 486.1, 154.7, 17],
                'additional' => [266.7, 517.3, 309.0, 110],
                'first_day' => [462.6, 665.4, 85.1, 15],
                'representative' => [36.6, 695.0, 251.3, 15],
                'business_name' => [36.7, 725.3, 202.2, 13.7],
                'business_address' => [245.8, 725.3, 329.2, 13.7],
            ],
            'checks' => [],
            'signature' => [296.0, 690.0, 18],
            'date' => [492.0, 697.0],
        ],
    ];

    /** The width of one box on a comb line, in points. */
    private const COMB_CELL = 14.4;

    /**
     * The filled, signed form as a PDF.
     *
     * @param  array<string, string|bool|null>  $values  keys of LAYOUT text, and checks as key or key:option
     */
    public static function render(string $kind, array $values, ?string $signaturePng, string $date, ?string $basePng = null): string
    {
        $values = self::onlyChosen($kind, $values);
        $layout = self::LAYOUT[$kind];
        $image = base64_encode($basePng ?? (string) file_get_contents(resource_path('hr/tax-forms/'.$layout['image'])));
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
            if (in_array($key, $layout['comb'] ?? [], true) || isset($layout['comb_cells'][$key])) {
                $cell = $layout['comb_cells'][$key] ?? self::COMB_CELL;
                foreach (mb_str_split(preg_replace('/\D/', '', $value) ?? '') as $i => $digit) {
                    $out .= sprintf('<div class="t" style="left:%.1fpt;top:%.1fpt;width:%.1fpt;text-align:center">%s</div>',
                        $x + $i * $cell, $y + ($h - 9) / 2, $cell, $e($digit));
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
    public static function renderPng(string $kind, array $values, ?string $signaturePng, string $date, ?string $basePng = null): string
    {
        $values = self::onlyChosen($kind, $values);
        $layout = self::LAYOUT[$kind];
        $page = $basePng !== null
            ? imagecreatefromstring($basePng)
            : imagecreatefrompng(resource_path('hr/tax-forms/'.$layout['image']));
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
            if (in_array($key, $layout['comb'] ?? [], true) || isset($layout['comb_cells'][$key])) {
                $cell = $layout['comb_cells'][$key] ?? self::COMB_CELL;
                foreach (mb_str_split(preg_replace('/\D/', '', $value) ?? '') as $i => $digit) {
                    $write($x + $i * $cell + $cell / 2 - 2.5, $y + ($h - 9) / 2, $digit);
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

    /**
     * A W-9's LLC code and "other" text print only for the classification
     * that asks for them; a hidden box left filled is dropped (full-code
     * audit, 2026-11-27).
     */
    private static function onlyChosen(string $kind, array $values): array
    {
        if ($kind !== self::W9) {
            return $values;
        }
        if (($values['classification'] ?? null) !== 'llc') {
            unset($values['llc_code']);
        }
        if (($values['classification'] ?? null) !== 'other') {
            unset($values['other_classification']);
        }

        return $values;
    }
}
