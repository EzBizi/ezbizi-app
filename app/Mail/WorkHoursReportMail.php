<?php

namespace App\Mail;

use App\Models\Organization;
use App\Support\Reports\Texts\ReportPdf;
use App\Support\Settings\OrgTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

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

    /** The same table as a formatted PDF (Jose, 2026-11-10: every report, its PDF too). */
    public function attachments(): array
    {
        $body = '*'.$this->reportName."*\n".$this->range."\n\n"
            .implode("\n", array_map(fn (array $r) => '• *'.$r['name'].'*: '.$r['hours'], $this->rows))
            ."\n\n*TOTAL: ".$this->total.'*';

        return [Attachment::fromData(fn () => ReportPdf::render($body, new Organization(['name' => $this->businessName ?: 'EzBizi']),
            CarbonImmutable::now(OrgTimezone::of())), Str::slug($this->reportName.' '.$this->range).'.pdf')->withMime('application/pdf')];
    }
}
