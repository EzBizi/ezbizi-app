<?php

namespace App\Support\Email;

use App\Models\Mailbox;

/**
 * A reply, composed as an RFC 5322 message — AS THE INBOX.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  *"info replies as info."*
 *
 *  The From line is built from the MAILBOX record and from nothing
 *  else. Look at the signature of {@see compose}: there is no User
 *  parameter. Not "the user is ignored" — there is no way to pass one,
 *  so no future edit to a controller can start putting the individual
 *  on the message by accident.
 *
 *  Why it matters, in the owner's terms. A customer who wrote to
 *  info@ and gets an answer from jenny.smith@ has been handed a
 *  person's address they never asked for — and their next reply then
 *  goes to a mailbox only Jenny can see, which quietly undoes the
 *  whole point of a shared inbox the first time she takes a week off.
 *
 *  Who actually typed it is recorded in the audit log and carried on
 *  the automation event. That is where an internal question about who
 *  said what belongs; it is not a header on somebody else's mail.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Why this is a class and not four lines in the reader ─────────
 *
 * The same reason {@see GmailMessageMapper} is: it is the half of the reply path
 * that can be exercised without a provider account. Composing headers is also
 * the one place in this module that BUILDS mail rather than reading it, which
 * makes it the one place a header-injection hole could exist — see
 * {@see headerValue} — and a hole in a private method of an untestable class is
 * a hole nothing can be asserted about.
 */
final class ReplyComposer
{
    /**
     * The message, as bytes.
     *
     * @param  MessageSummary  $original  the message being answered — supplies
     *                                    the recipient and the subject
     * @param  array<string, string>  $headers  the original's headers, lower-cased,
     *                                          for threading
     */
    public function compose(Mailbox $mailbox, MessageSummary $original, array $headers, string $body): string
    {
        $lines = [
            // The inbox. Composed from the record, every time, with no
            // alternative path and no parameter that could override it.
            'From: '.$this->headerValue(sprintf('%s <%s>', $mailbox->display_name, $mailbox->address)),
            'To: '.$this->headerValue($original->fromAddress),
            'Subject: '.$this->headerValue($this->subject($original->subject)),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=utf-8',
        ];

        /*
         * Threading, so the answer lands under the question in the customer's
         * own mail program rather than as a new conversation. Absent when the
         * original had no Message-ID, which is legal and happens with some
         * automated senders — a reply with no In-Reply-To is untidy, and a
         * reply with an empty one is broken.
         */
        $messageId = trim($headers['message-id'] ?? '');

        if ($messageId !== '') {
            $lines[] = 'In-Reply-To: '.$this->headerValue($messageId);
            $lines[] = 'References: '.$this->headerValue(
                trim(($headers['references'] ?? '').' '.$messageId)
            );
        }

        /*
         * The blank line is what makes everything after it BODY.
         *
         * So a newline in the body is just a newline — it cannot introduce a
         * header, because headers ended at the blank line. That is why only the
         * values above are scrubbed and the body is not: scrubbing the body
         * would destroy the paragraphs somebody typed, for no gain.
         */
        return implode("\r\n", $lines)."\r\n\r\n".str_replace("\r\n", "\n", $body);
    }

    /** The same message, base64url and unpadded, as Gmail's `messages.send` wants it. */
    public function composeForGmail(Mailbox $mailbox, MessageSummary $original, array $headers, string $body): string
    {
        return rtrim(strtr(base64_encode($this->compose($mailbox, $original, $headers, $body)), '+/', '-_'), '=');
    }

    /**
     * `Re:` once, never twice.
     *
     * Case-insensitive, because a customer's mail program may have written
     * `RE:` or `Re :`. Getting this wrong produces `Re: Re: Re: Re: Invoice`,
     * which is only cosmetic — and cosmetic on the message a customer receives
     * from the business.
     */
    private function subject(string $original): string
    {
        $subject = trim($original);

        if ($subject === '') {
            // A reply to a message with no subject still needs one, or some
            // servers treat it as suspicious.
            return 'Re: (no subject)';
        }

        return preg_match('/^re\s*:/i', $subject) === 1 ? $subject : 'Re: '.$subject;
    }

    /**
     * THE HEADER-INJECTION GUARD, and the reason this class exists at all.
     *
     * A header is `Name: value`, terminated by CRLF. So a VALUE containing a
     * newline ends the header early and everything after it becomes a NEW
     * header — which means a subject of
     *
     *     Invoice\r\nBcc: everyone@example.com
     *
     * silently copies the business's reply to whoever the attacker chose. And
     * the subject here comes from the message being REPLIED TO, which is to say
     * from whoever sent it: this is attacker-controlled input by construction,
     * not by accident.
     *
     * A bare CR is enough on its own — some mail servers accept it as a line
     * ending — so all three of CR, LF and NUL go. Replaced with a space rather
     * than removed, so `Invoice\r\nBcc:` becomes one obviously-odd subject line
     * instead of `InvoiceBcc:`, which reads as a legitimate word.
     *
     * Applied to EVERY value, including the ones composed from our own record.
     * "This one is ours, so it is safe" is exactly the reasoning that leaves the
     * next one open — and `display_name` is typed by an administrator into a
     * form, which is not the same thing as being trustworthy.
     */
    private function headerValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00]+/', ' ', $value));
    }
}
