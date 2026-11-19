<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Monday's company payroll (Merav via Jose, 2026-11-19): the week's totals in
 * the body and the sheet attached, a tab a branch. Not queued, like the
 * weekly hours mail: the command reports a failure where it can be seen.
 */
class PayrollSheetMail extends Mailable
{
    /** @param  array{week_start: string, label: string, totals: array<string, mixed>}  $week */
    public function __construct(
        public readonly string $businessName,
        public readonly array $week,
        public readonly string $xlsx,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->businessName} payroll — {$this->week['label']}");
    }

    public function content(): Content
    {
        $t = $this->week['totals'];

        return new Content(htmlString: '<p>The payroll for <strong>'.e($this->week['label']).'</strong> is attached, one tab a branch.</p>'
            .'<p>'.e($t['people']).' people · '.e($t['hours']).' hours · $'.e(number_format((float) $t['pay'], 2)).'<br>'
            .e($t['waiting']).' not approved by their manager · '.e($t['with_warnings']).' with something missing (see the Warnings column).</p>');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->xlsx, 'payroll-'.$this->week['week_start'].'.xlsx')
            ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')];
    }
}
