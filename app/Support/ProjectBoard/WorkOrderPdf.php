<?php

namespace App\Support\ProjectBoard;

use App\Support\Files\HardenedPdf;

/**
 * A job's work order for the repair crew (Jose, 2026-11-17): Workiz job
 * number, client, address, phone with extension, technician, repair team,
 * the parts list (where each is picked up), the attachments (photos and
 * inspection reports), payment date and amounts, and the payment rule in
 * bold: the crew does not leave without confirmed payment.
 *
 * Built from a plain array so a test can render it without a job.
 */
final class WorkOrderPdf
{
    /** Jose, 2026-11-17: printed on every work order; a reminder, not a block. */
    public const PAYMENT_RULE = "Don't leave the job site without confirmation of collected payment. "
        .'Reach out to the project manager so we can collect payment from the client.';

    /**
     * @param  array{
     *     company: ?string, number: ?string, workiz: ?string, client: ?string, address: ?string,
     *     phone: ?string, phone_ext: ?string, technician: ?string, repair_team: ?string,
     *     parts: list<array<string, mixed>>, photos: list<array{name: string, data: ?string}>,
     *     files: list<string>, inspections: list<string>, payment_date: ?string,
     *     money: ?array{total: string, paid: string, remaining: string}
     * }  $w
     */
    public static function render(array $w): string
    {
        return HardenedPdf::render(self::html($w), 'letter', 'Page {PAGE_NUM} of {PAGE_COUNT}');
    }

    /** @param  array<string, mixed>  $w  see [render] */
    public static function html(array $w): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $or = fn (?string $s) => filled($s) ? $e($s) : '<span class="none">-</span>';
        $phone = filled($w['phone'] ?? null)
            ? $e($w['phone']).(filled($w['phone_ext'] ?? null) ? ' ext. '.$e($w['phone_ext']) : '')
            : null;

        $facts = [
            ['Workiz job #', $or($w['workiz'] ?? null)],
            ['EzBizi job #', $or($w['number'] ?? null)],
            ['Client', $or($w['client'] ?? null)],
            ['Address', $or($w['address'] ?? null)],
            ['Phone', $phone ?? $or(null)],
            ['Technician', $or($w['technician'] ?? null)],
            ['Repair team', $or($w['repair_team'] ?? null)],
        ];
        $factRows = implode('', array_map(fn ($f) => '<tr><th>'.$f[0].'</th><td>'.$f[1].'</td></tr>', $facts));

        $parts = '';
        foreach ($w['parts'] ?? [] as $p) {
            $supplier = is_array($p['supplier'] ?? null) ? $p['supplier'] : null;
            $where = match ($p['destination'] ?? null) {
                'warehouse' => 'Warehouse pickup',
                'customer' => "At the customer's location",
                'supplier' => 'Pick up at '.trim(($supplier['name'] ?? 'the supplier').(filled($supplier['address'] ?? null) ? ', '.$supplier['address'] : '')),
                default => ($p['kind'] ?? '') === 'stock' ? 'In stock' : '',
            };
            $state = match ($p['kind'] ?? '') {
                'stock' => 'In stock',
                'pickup' => filled($p['received_at'] ?? null) ? 'Picked up' : 'Same-day pickup',
                default => filled($p['received_at'] ?? null) ? 'Received' : 'Ordered',
            };
            $parts .= '<tr><td>'.$e((string) ($p['item'] ?? '')).'</td><td class="n">'.(int) ($p['quantity'] ?? 1).'</td><td>'
                .$e($state).'</td><td>'.$e($where).'</td></tr>';
        }
        $parts = $parts === ''
            ? '<p class="none">No parts listed.</p>'
            : '<table class="grid"><tr><th>Part</th><th class="n">Qty</th><th>Status</th><th>Pickup</th></tr>'.$parts.'</table>';

        $photos = '';
        foreach ($w['photos'] ?? [] as $photo) {
            $photos .= $photo['data'] !== null
                ? '<div class="photo"><img src="'.$e($photo['data']).'"><div>'.$e($photo['name']).'</div></div>'
                : '';
        }
        $listed = array_merge(
            array_map(fn ($i) => 'Inspection report: '.$i, $w['inspections'] ?? []),
            $w['files'] ?? [],
        );
        $attachments = ($photos === '' ? '' : '<div class="photos">'.$photos.'</div>')
            .($listed === [] ? '' : '<ul>'.implode('', array_map(fn ($f) => '<li>'.$e($f).'</li>', $listed)).'</ul>');
        if ($attachments === '') {
            $attachments = '<p class="none">No attachments.</p>';
        }

        $money = $w['money'] ?? null;
        $payment = '<table class="facts"><tr><th>Payment date</th><td>'.$or($w['payment_date'] ?? null).'</td></tr>'
            .($money === null ? '' : '<tr><th>Total</th><td>'.$e($money['total']).'</td></tr><tr><th>Paid</th><td>'
                .$e($money['paid']).'</td></tr><tr><th>Remaining balance</th><td><b>'.$e($money['remaining']).'</b></td></tr>')
            .'</table>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111;margin:28px}
            h1{font-size:20px;margin:0 0 2px} .co{color:#555;margin-bottom:14px}
            h2{font-size:13px;margin:16px 0 6px;border-bottom:1px solid #999;padding-bottom:2px}
            table.facts th{text-align:left;color:#444;font-weight:bold;padding:2px 14px 2px 0;width:130px;vertical-align:top}
            table.facts td{padding:2px 0}
            table.grid{border-collapse:collapse;width:100%} table.grid th,table.grid td{border:1px solid #888;padding:4px 6px;text-align:left}
            .n{text-align:right} .none{color:#777}
            .photos{width:100%} .photo{display:inline-block;width:31%;margin:0 1% 8px 0;vertical-align:top;font-size:9px;color:#555}
            .photo img{max-width:100%;max-height:150px}
            .rule{margin-top:18px;border:2px solid #111;padding:8px 10px;font-weight:bold;font-size:12px}
            </style></head><body>'
            .'<h1>Work order</h1><div class="co">'.$e($w['company'] ?? '').'</div>'
            .'<table class="facts">'.$factRows.'</table>'
            .'<h2>Parts</h2>'.$parts
            .'<h2>Attachments</h2>'.$attachments
            .'<h2>Payment</h2>'.$payment
            .'<div class="rule">'.$e(self::PAYMENT_RULE).'</div>'
            .'</body></html>';
    }
}
