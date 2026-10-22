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
    public function compose(Mailbox $mailbox, MessageSummary $original, array $headers, string $body, array $to = [], array $cc = []): string
    {
        // Each address through the guard on its own, so one cannot smuggle a header past the join.
        $to = $to === [] ? [$original->fromAddress] : $to;

        $lines = [
            // The inbox. Composed from the record, every time, with no
            // alternative path and no parameter that could override it.
            'From: '.$this->headerValue(sprintf('%s <%s>', $mailbox->display_name, $mailbox->address)),
            'To: '.implode(', ', array_map(fn (string $a) => $this->headerValue($a), $to)),
            ...($cc === [] ? [] : ['Cc: '.implode(', ', array_map(fn (string $a) => $this->headerValue($a), $cc))]),
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
    public function composeForGmail(Mailbox $mailbox, MessageSummary $original, array $headers, string $body, array $to = [], array $cc = []): string
    {
        return rtrim(strtr(base64_encode($this->compose($mailbox, $original, $headers, $body, $to, $cc)), '+/', '-_'), '=');
    }

    /**
     * A NEW message — same rules, no original to answer.
     *
     * ── Why this lives in a class called ReplyComposer ───────────
     *
     * Because everything that makes composing dangerous is identical, and
     * splitting it would mean two copies of {@see headerValue}. The From rule is
     * the same rule ("info sends as info", and there is still no User
     * parameter), the injection guard is the same guard, and the blank line that
     * separates headers from body is the same line. What differs is only that
     * there is no {@see MessageSummary}: the recipient and the subject are typed
     * by a person instead of read off the message being answered.
     *
     * ── The recipient is now OUR input, which is worse, not better ─
     *
     * On the reply path the subject arrives from whoever sent the original, and
     * the docblock on {@see headerValue} says plainly that this is
     * attacker-controlled by construction. It is tempting to relax here on the
     * grounds that a signed-in colleague typed these. Do not. A colleague typing
     *
     *     someone@example.com<CR><LF>Bcc: everyone@competitor.com
     *
     * into a To field is the same hole with a friendlier face, and "our own
     * staff would not" is not an access control. Every value goes through the
     * guard, recipients individually so that one bad address cannot smuggle a
     * header past the join.
     *
     * @param  list<string>  $to  one or more recipient addresses
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     */
    public function composeNew(
        Mailbox $mailbox,
        array $to,
        string $subject,
        string $body,
        array $attachments = [],
    ): string {
        $recipients = array_values(array_filter(array_map(
            fn (string $address) => $this->headerValue($address),
            $to,
        ), fn (string $address) => $address !== ''));

        $lines = [
            'From: '.$this->headerValue(sprintf('%s <%s>', $mailbox->display_name, $mailbox->address)),
            'To: '.implode(', ', $recipients),
            'Subject: '.$this->headerValue($this->subjectForNew($subject)),
            'MIME-Version: 1.0',
        ];

        // No In-Reply-To and no References, deliberately. This message is not
        // under anything, and inventing threading headers for it would file it
        // beneath an unrelated conversation in the recipient's mail program.

        if ($attachments === []) {
            $lines[] = 'Content-Type: text/plain; charset=utf-8';

            return implode("\r\n", $lines)."\r\n\r\n".str_replace("\r\n", "\n", $body);
        }

        return $this->multipart($lines, $body, $attachments);
    }

    /**
     * The same new message, base64url and unpadded, as Gmail's `messages.send`
     * wants it. Attachments travel inside the MIME, so Gmail needs no separate
     * upload for anything this size.
     *
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     */
    public function composeNewForGmail(
        Mailbox $mailbox,
        array $to,
        string $subject,
        string $body,
        array $attachments = [],
    ): string {
        return rtrim(strtr(
            base64_encode($this->composeNew($mailbox, $to, $subject, $body, $attachments)),
            '+/',
            '-_',
        ), '=');
    }

    /**
     * A `multipart/mixed` message: the typed text, then the files.
     *
     * ── The boundary must not occur inside any part ──────────────
     *
     * That is the one correctness rule of multipart, and getting it wrong
     * truncates the message at whatever line happens to collide — the reader
     * sees half a message and no error. The boundary here is 128 bits of
     * `random_bytes`, so a collision is not "unlikely", it is not going to
     * happen; and it is generated per message rather than fixed, so a file
     * whose CONTENTS are a previous boundary cannot break the next send.
     *
     * A fixed boundary string would be the cheaper-looking choice and is the
     * bug: attach a saved copy of an email built by this composer and its
     * boundary lines are now inside a part.
     *
     * ── Base64, always, and hard-wrapped ─────────────────────────
     *
     * Not quoted-printable and not 8bit: a PDF down an 8bit part is what
     * arrives corrupted through one hop that does not speak the extension. The
     * 76-character wrap is RFC 2045's line-length rule, and servers do enforce
     * it.
     *
     * @param  list<string>  $lines  the headers built so far
     * @param  list<array{name: string, mime: string, bytes: string}>  $attachments
     */
    private function multipart(array $lines, string $body, array $attachments): string
    {
        $boundary = 'ezbizi-'.bin2hex(random_bytes(16));

        $lines[] = 'Content-Type: multipart/mixed; boundary="'.$boundary.'"';

        $parts = [
            '--'.$boundary,
            'Content-Type: text/plain; charset=utf-8',
            '',
            str_replace("\r\n", "\n", $body),
        ];

        foreach ($attachments as $file) {
            $parts[] = '--'.$boundary;
            // The filename is scrubbed like any other header value — it comes
            // from whoever picked the file, and a newline in it would end the
            // header and start one of the sender's choosing.
            $parts[] = 'Content-Type: '.$this->headerValue($file['mime']);
            $parts[] = 'Content-Disposition: attachment; filename="'
                .str_replace('"', '', $this->headerValue($file['name'])).'"';
            $parts[] = 'Content-Transfer-Encoding: base64';
            $parts[] = '';
            $parts[] = trim(chunk_split(base64_encode($file['bytes']), 76, "\r\n"));
        }

        $parts[] = '--'.$boundary.'--';

        return implode("\r\n", $lines)."\r\n\r\n".implode("\r\n", $parts);
    }

    /**
     * A new message's Subject — never `Re:`, and never empty.
     *
     * The empty case is the same defence as the reply path's: some servers
     * treat a subjectless message as suspicious, and a business's mail landing
     * in spam because somebody left the field blank is a support call.
     */
    private function subjectForNew(string $subject): string
    {
        $subject = trim($subject);

        return $subject === '' ? '(no subject)' : $subject;
    }

    /**
     * The Subject a reply will carry, as the open-message payload tells the client.
     *
     * Public because the screen must print the same subject the composer will
     * put on the wire. Duplicating the Re: rule at the controller is how the
     * two drift.
     */
    public function subjectForReply(string $original): string
    {
        return $this->subject($original);
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
