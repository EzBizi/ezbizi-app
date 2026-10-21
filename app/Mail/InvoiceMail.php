<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * One or more invoices by email, from the organization's own sending server.
 * The body is the summary table (Jose's accounting email, 2026-10-20: each
 * branch, its jobs, each charge, its total, and a Total row); each invoice's
 * PDF is attached.
 */
class InvoiceMail extends Mailable
{
    /**
     * @param  list<Invoice>  $invoices
     * @param  list<string>  $columns  charge names, in order, for the table
     */
    public function __construct(
        public readonly array $invoices,
        public readonly array $columns,
        public readonly string $issuerName,
        public readonly string $subjectLine,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $money = fn (int $c) => '$'.number_format($c / 100, 2);
        $cell = 'style="border:1px solid #999;padding:4px 8px;text-align:right"';
        $left = 'style="border:1px solid #999;padding:4px 8px;text-align:left"';

        $head = '<tr><th '.$left.'>Company Name</th><th '.$cell.'>Total Jobs</th>';
        foreach ($this->columns as $c) {
            $head .= '<th '.$cell.'>'.$e($c).'</th>';
        }
        $head .= '<th '.$cell.'>Total</th></tr>';

        $body = '';
        $jobs = 0;
        $sums = array_fill_keys($this->columns, 0);
        $grand = 0;
        foreach ($this->invoices as $invoice) {
            $byName = $invoice->lines->pluck('amount_cents', 'description')->all();
            $n = (int) ($invoice->lines->first()?->meta['jobs'] ?? 0);
            $jobs += $n;
            $body .= '<tr><td '.$left.'>'.$e($invoice->bill_to_name).'</td><td '.$cell.'>'.$n.'</td>';
            foreach ($this->columns as $c) {
                $v = (int) ($byName[$c] ?? 0);
                $sums[$c] += $v;
                $body .= '<td '.$cell.'>'.$money($v).'</td>';
            }
            $grand += $invoice->total_cents;
            $body .= '<td '.$cell.'><strong>'.$money($invoice->total_cents).'</strong></td></tr>';
        }
        $foot = '<tr><td '.$left.'><strong>Total</strong></td><td '.$cell.'><strong>'.$jobs.'</strong></td>';
        foreach ($this->columns as $c) {
            $foot .= '<td '.$cell.'><strong>'.$money($sums[$c]).'</strong></td>';
        }
        $foot .= '<td '.$cell.'><strong>'.$money($grand).'</strong></td></tr>';

        return new Content(htmlString: '<div style="font-family:Arial,sans-serif;font-size:13px">'
            .'<p>Hello,</p><p>Please find the invoices attached.</p>'
            .'<table style="border-collapse:collapse">'.$head.$body.(count($this->invoices) > 1 ? $foot : '').'</table>'
            .'<p>Thank you,<br>'.$e($this->issuerName).'</p></div>');
    }

    public function attachments(): array
    {
        return array_values(array_filter(array_map(fn (Invoice $i) => $i->pdf_path === null ? null
            : Attachment::fromData(fn () => Storage::disk($i->pdf_disk)->get($i->pdf_path), $i->number.'.pdf')->withMime('application/pdf'),
            $this->invoices)));
    }
}
