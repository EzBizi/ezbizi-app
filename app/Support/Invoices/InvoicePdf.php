<?php

namespace App\Support\Invoices;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Organization;
use App\Support\Files\FileStore;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * An invoice as a PDF in the issuer's own brand: its logo, its address, the
 * word INVOICE, number / date / due, Bill to, the summary line ("Total jobs:
 * 78"), an Expenses name | Amount table and the total. Laid out after the
 * invoice Jose showed (2026-10-20) without anything of A-1's in it: every word
 * and image comes from the tenant's own records.
 */
final class InvoicePdf
{
    public function __construct(private readonly FileStore $files) {}

    /** Renders and stores it on the invoice; returns the bytes. */
    public function store(Invoice $invoice): string
    {
        $bytes = $this->render($invoice);
        $disk = $this->files->defaultDisk();
        $path = $this->files->directoryFor('invoices', $invoice->organization_id).'/'.$invoice->id.'.pdf';
        Storage::disk($disk)->put($path, $bytes);
        $invoice->forceFill(['pdf_disk' => $disk, 'pdf_path' => $path])->save();

        return $bytes;
    }

    public function render(Invoice $invoice): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($invoice));
        $pdf->setPaper('letter');
        $pdf->render();

        return (string) $pdf->output();
    }

    public function html(Invoice $invoice): string
    {
        $issuer = $invoice->company ?? Organization::query()->find($invoice->organization_id);
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn (int $cents) => ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);
        $date = fn ($d) => $d?->format('m/d/Y') ?? '';

        $logo = '';
        if ($issuer?->logo_path) {
            $disk = $this->files->defaultDisk();
            if (Storage::disk($disk)->exists($issuer->logo_path)) {
                $mime = Storage::disk($disk)->mimeType($issuer->logo_path) ?: 'image/png';
                $logo = '<img src="data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($issuer->logo_path)).'" style="max-height:110px;max-width:520px">';
            }
        }
        $address = $issuer instanceof Company || $issuer instanceof Organization
            ? implode('<br>', array_filter([$e($issuer->address_line1), $e($issuer->address_line2),
                $e(trim(($issuer->city ?? '').', '.($issuer->state ?? '').' '.($issuer->postal_code ?? ''), ', '))]))
            : '';

        $rows = '';
        foreach ($invoice->lines as $line) {
            $rows .= '<tr><td>'.$e($line->description).'</td><td class="n">'.$money($line->amount_cents).'</td></tr>';
        }
        $tax = $invoice->tax_cents > 0 ? '<tr><td>Tax</td><td class="n">'.$money($invoice->tax_cents).'</td></tr>' : '';

        return '<!doctype html><html><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#111;margin:36px}
            .logo{text-align:center;margin-bottom:24px} h1{text-align:center;font-weight:400;font-size:26px;margin:18px 0 28px}
            .top{width:100%} .top td{vertical-align:top} .meta td{padding:2px 0 2px 16px}
            .bill{margin:28px 0} table.lines{border-collapse:collapse;width:70%;margin:0 auto}
            table.lines th,table.lines td{border:1px solid #333;padding:5px 8px;text-align:left} .n{text-align:right}
            .total{width:70%;margin:6px auto;text-align:right;font-weight:bold} .summary{width:70%;margin:0 auto 4px}
            .notes{margin-top:24px;color:#444}
        </style></head><body>'
            .'<div class="logo">'.$logo.'</div><h1>INVOICE</h1>'
            .'<table class="top"><tr><td><strong>'.$e($issuer?->name).'</strong><br>'.$address.'</td>'
            .'<td style="text-align:right"><table class="meta" style="margin-left:auto">'
            .'<tr><td>Invoice Number:</td><td>'.$e($invoice->number).'</td></tr>'
            .'<tr><td>Invoice Date:</td><td>'.$date($invoice->issued_on).'</td></tr>'
            .'<tr><td>Due Date:</td><td>'.$date($invoice->due_on ?? $invoice->issued_on).'</td></tr>'
            .'</table></td></tr></table>'
            .'<div class="bill">Bill To:<br><strong>'.$e($invoice->bill_to_name).'</strong><br>'.nl2br($e($invoice->bill_to_address)).'</div>'
            .($invoice->summary ? '<div class="summary">'.$e($invoice->summary).'</div>' : '')
            .'<table class="lines"><tr><th>Expenses Name</th><th class="n">Amount</th></tr>'.$rows.$tax.'</table>'
            .'<div class="total">'.$money($invoice->total_cents).'</div>'
            .($invoice->notes ? '<div class="notes">'.nl2br($e($invoice->notes)).'</div>' : '')
            .'</body></html>';
    }
}
