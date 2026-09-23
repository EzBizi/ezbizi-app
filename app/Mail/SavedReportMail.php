<?php

namespace App\Mail;

use App\Models\SavedReport;
use App\Support\Reports\ReportCsv;
use App\Support\Reports\ReportResult;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A saved report, mailed.
 *
 * ── The rows are ATTACHED, not in the body ──────────────────────
 *
 * A twelve-column report rendered as an HTML table is unreadable in most mail
 * clients and unusable on a phone. The body says what arrived and whether it
 * was cut short; the CSV is the report. That also means the recipient can open
 * it in the thing they were going to open it in anyway.
 *
 * ── The subject names the report and the day ────────────────────
 *
 * A scheduled report arrives repeatedly and lands in a thread with its own
 * previous copies. A subject that is only the report's name makes yesterday's
 * and today's indistinguishable in a list.
 */
class SavedReportMail extends Mailable
{
    /**
     * @param  string  $businessName  the business this report is about, named in
     *                                the subcopy so a recipient can tell who
     *                                actually sent it
     */
    public function __construct(
        public readonly SavedReport $report,
        public readonly ReportResult $result,
        public readonly string $businessName = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->report->name.' — '.now()->toFormattedDateString(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.saved-report',
            with: [
                'name' => $this->report->name,
                'summary' => $this->report->description,
                'count' => $this->result->count,
                'capped' => $this->result->capped,

                /*
                 * WHO THIS IS FROM, and it is a published promise rather than a
                 * nicety.
                 *
                 * ezbizi.com/privacy says of mail EzBizi sends for a business:
                 * "Those leave from EzBizi's own address rather than the
                 * organization's, and the body says which business the work is
                 * about." That sentence was true of the inspection report and
                 * of AutomationMessage, and false here — this subcopy named the
                 * rule and never the business, so a customer received a CSV
                 * about their own data from noreply@ezbizi.com and was told an
                 * automation rule sent it. Raised by the SES session, 2026-09-22.
                 *
                 * Defaulted to empty rather than required: a mailable is
                 * constructed in places a scope is not to hand, and an
                 * unnamed business is a missing sentence rather than a crash
                 * on somebody's report run. The template omits the clause
                 * rather than printing "Sent by  through EzBizi."
                 */
                'business' => $this->businessName,
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        // The name carries the date for the same reason the subject does: these
        // land in a downloads folder together.
        $file = preg_replace('/[^A-Za-z0-9]+/', '-', $this->report->name);

        return [
            Attachment::fromData(
                fn () => ReportCsv::render($this->result),
                trim((string) $file, '-').'-'.now()->toDateString().'.csv',
            )->withMime('text/csv'),
        ];
    }
}
