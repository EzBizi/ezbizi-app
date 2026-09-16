<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * An email an automation sends, from EzBizi, carrying words a tenant wrote.
 *
 * Jose reversed the September ruling on 2026-09-16 and drew the line himself:
 *
 *   "actions like send an email or send a whatsapp / Email ( the Ezbizi SES
 *    one, not the company one )"
 *
 * So this is EzBizi's transport and EzBizi's From, with a subject and a body an
 * administrator composed in a rule. That combination is why the envelope lives
 * here rather than in the Automations module: what a message from ezbizi.com
 * says about itself is one decision in one place, and the module that supplies
 * the words is not the module that should decide what the wrapper claims.
 *
 * ── Why no attachments, and why the parameter does not exist ─────
 *
 * An attachment from ezbizi.com on a message a tenant composed is a phishing
 * vector wearing our domain — and our SES reputation is shared with every sign
 * in, password reset and invitation the platform sends. Automations does not
 * offer the parameter at all rather than accepting one and dropping it, because
 * a field that silently ignores what somebody typed is the defect class that
 * module spent a week removing. There is nothing here to attach one with.
 *
 * ── Plain text, and that is a deliberate ceiling ─────────────────
 *
 * The body is what the rule author typed. It is NOT rendered as HTML and not
 * passed through a template that could make it look like a transactional EzBizi
 * message — a tenant-authored body dressed in our own mail styling is the
 * confusion this envelope exists to prevent. Somebody reading it should be able
 * to tell that a business wrote it and EzBizi carried it.
 *
 * ponytail: plain text, no markdown rendering. If rich bodies are ever wanted
 * they need a sanitiser and a decision about what our chrome may surround, and
 * that is a different feature rather than a bigger parameter here.
 */
class AutomationMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $subjectLine  what the rule author typed
     * @param  string  $body  what the rule author typed, sent as written
     * @param  string  $businessName  the business the rule belongs to, named in
     *                                the footer so a recipient can tell who
     *                                actually wrote to them
     */
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly string $businessName,
    ) {}

    public function build(): self
    {
        /*
         * The footer is not decoration and it is not removable by the rule
         * author. A message from ezbizi.com carrying a business's words needs to
         * say whose words they are, or every one of these looks like EzBizi
         * writing to a customer it has no relationship with.
         *
         * Appended to the BODY rather than set as a header, because a header is
         * not read by the person the message confuses.
         */
        $footer = "\n\n--\nSent by {$this->businessName} through EzBizi.";

        return $this->subject($this->subjectLine)
            ->text('emails.automation-plain', [
                'body' => $this->body.$footer,
            ]);
    }
}
