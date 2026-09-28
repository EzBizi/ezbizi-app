<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The weekly hours email (2026-09-28): one row per person with their hours,
 * then a total row — the table A1 Tools' weekly_time_report sends today, so
 * the people reading it on Monday see the same thing they always have.
 *
 * Not queued: the sender is either a person pressing Send now, who is told
 * how it went, or the automation runner, which records the outcome on the
 * rule's run. A queued mail would report success for a message that later
 * failed where neither of them could see it.
 */
class WorkHoursReportMail extends Mailable
{
    /**
     * @param  list<array{name: string, hours: string}>  $rows
     */
    public function __construct(
        public readonly string $reportName,
        public readonly string $range,
        public readonly array $rows,
        public readonly string $total,
        public readonly string $businessName = '',
        public readonly bool $fromAutomation = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->reportName} — {$this->range}");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.work-hours-report',
            with: [
                'name' => $this->reportName,
                'range' => $this->range,
                'rows' => $this->rows,
                'total' => $this->total,
                'business' => $this->businessName,
                'fromAutomation' => $this->fromAutomation,
            ],
        );
    }
}
