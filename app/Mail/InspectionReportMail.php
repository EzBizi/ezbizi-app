<?php

namespace App\Mail;

use App\Models\InspectionReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A finished inspection, sent to the customer it was carried out for.
 *
 * ── Why this is a Mailable and not a Notification ────────────────
 *
 * `MailDispatcher::asTenant()` takes a `Mailable`, and this must go through
 * asTenant rather than platform(): the sender is A-1 Chimney writing to their
 * own customer, not EzBizi writing to a user of EzBizi. Those are the two doors
 * MailDispatcher deliberately separates, and a report leaving under the EzBizi
 * address would be the wrong one — the customer has no relationship with us.
 *
 * Notifications address a `Notifiable`; a Customer is not one, and making it
 * one to reuse the channel would put a login-shaped interface on a record that
 * has no account.
 *
 * ── The subject names the company, and deliberately not the address ──
 *
 * `EmailVerificationLink` keeps the organization OUT of its subject because
 * that subject is read on a lock screen and would tell a bystander where
 * somebody works. The opposite applies here: the recipient is a customer who
 * needs to know which company is writing to them before they open an
 * attachment. What stays out is the SITE — "14 Maple Street" on a lock screen
 * tells a bystander where the reader lives.
 *
 * ── The PDF is attached, not linked ──────────────────────────────
 *
 * A link would need either a public URL for a document containing a customer's
 * address and photographs, or a login the customer does not have. The report is
 * already frozen at submission, so the attachment cannot drift from what the
 * technician filed.
 */
class InspectionReportMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $pdf  the rendered bytes, not a path — see attachments()
     * @param  string  $note  a covering line the rule's author wrote, may be empty
     */
    public function __construct(
        InspectionReport $report,
        private readonly string $companyName,
        string $pdf,
        private readonly string $note = '',
    ) {
        // Scalars, not the model: the queue worker restores a model with no
        // tenant in context, and the customer read refused the first real send.
        $this->customerName = $report->customer?->name;
        $this->definitionName = (string) $report->definition_name;
        $this->submittedOn = $report->submitted_at?->format('Y-m-d');
        // Base64 because the queue JSON-encodes this object: raw PDF bytes are
        // not UTF-8 and the dispatch threw "Malformed UTF-8" on the first real send.
        $this->pdf = base64_encode($pdf);
    }

    private readonly string $pdf;

    private readonly ?string $customerName;

    private readonly string $definitionName;

    private readonly ?string $submittedOn;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s — your %s', $this->companyName, $this->definitionName),
        );
    }

    /**
     * Built as an HTML string rather than a view, because this application has
     * no mail views: every other message is a `MailMessage` from a Notification,
     * and that route is closed to a Mailable. Everything interpolated is escaped
     * — the note is typed by a rule's author and the names come from tenant
     * records, so neither is trusted markup.
     */
    public function content(): Content
    {
        $lines = [
            sprintf('<p>Hello %s,</p>', e($this->customerName ?? 'there')),
            sprintf(
                '<p>Your %s has been completed by %s. It is attached to this message '
                .'as a PDF.</p>',
                e($this->definitionName),
                e($this->companyName),
            ),
        ];

        if (trim($this->note) !== '') {
            $lines[] = sprintf('<p>%s</p>', nl2br(e(trim($this->note))));
        }

        $lines[] = sprintf('<p>%s</p>', e($this->companyName));

        return new Content(htmlString: implode("\n", $lines));
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => base64_decode($this->pdf), $this->filename())
                ->withMime('application/pdf'),
        ];
    }

    /**
     * Named for the customer's filing cabinet, not ours.
     *
     * No customer name and no address in the filename: it is visible in a mail
     * client's attachment list and in any forward of this message.
     */
    private function filename(): string
    {
        $stem = preg_replace('/[^A-Za-z0-9]+/', '-', $this->definitionName ?: 'inspection');

        return trim((string) $stem, '-').'-'.$this->submittedOn.'.pdf';
    }
}
