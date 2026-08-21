<?php

namespace App\Support\Email;

use Illuminate\Support\Carbon;

/**
 * Gmail's JSON into this application's own shapes.
 *
 * ── Why the mapping is its own class ─────────────────────────────
 *
 * Because it is the only half of the Gmail reader that can be exercised without
 * a Google account. Everything else in {@see GmailMailboxReader} is a token
 * exchange and an HTTP round trip, and neither can run in a test suite on an
 * installation with no OAuth client registered — but the mapping is pure: a
 * documented payload in, a {@see MessageSummary} out, no clock, no network and
 * no state.
 *
 * Splitting it out is therefore not tidiness. It is the difference between a
 * provider adapter that is entirely untestable and one whose interesting part is
 * covered — including the parts that go wrong in practice: a `From` header with
 * no display name, a multipart message whose HTML part is nested three levels
 * down, a message with no text part at all, and base64url that is not padded.
 *
 * ── Everything here is hostile input ─────────────────────────────
 *
 * A header value is written by whoever sent the message. `??` and casts are
 * everywhere below deliberately: a missing `payload`, a `headers` that is an
 * object rather than a list, a `parts` containing a scalar — every one of those
 * is a message somebody can send, and none of them may be an exception that
 * takes the inbox down. What cannot be read becomes an empty string, and the
 * message still lists.
 */
final class GmailMessageMapper
{
    public function __construct(
        private readonly BodySanitiser $sanitiser,
    ) {}

    /**
     * A list row from `users.messages.get` with `format=metadata`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function summary(array $payload): MessageSummary
    {
        $headers = $this->headers($payload);
        [$fromName, $fromAddress] = $this->splitAddress($headers['from'] ?? '');

        return new MessageSummary(
            id:         (string) ($payload['id'] ?? ''),
            threadId:   isset($payload['threadId']) ? (string) $payload['threadId'] : null,
            subject:    $this->line($headers['subject'] ?? ''),
            fromName:   $fromName,
            fromAddress: $fromAddress,
            to:         $this->addressList($headers['to'] ?? ''),
            sentAt:     $this->sentAt($payload, $headers),
            snippet:    $this->line((string) ($payload['snippet'] ?? '')),
            hasAttachments: $this->hasAttachments($payload['payload'] ?? []),

            /*
             * Gmail's own unread flag, and it is NOT EzBizi's seen state.
             *
             * They answer different questions and a screen that conflates them
             * is wrong both ways round. UNREAD is about the mailbox as Google
             * sees it — one flag, shared by everybody, changed by anybody who
             * opens the message in Gmail itself. `message_seen` is about the
             * PEOPLE here: four colleagues, and which of them has looked. A
             * message can be read in Gmail by the owner and seen by nobody in
             * EzBizi, and the shared inbox cares about the second.
             */
            unreadAtProvider: in_array('UNREAD', (array) ($payload['labelIds'] ?? []), strict: true),
        );
    }

    /**
     * The whole message, with the body sanitised into blocks.
     *
     * ── HTML is preferred, and plain text is the fallback ────────
     *
     * The other way round is tempting — plain text has no markup, so it "must"
     * be safer — and it is the wrong call for a reader people have to use:
     * the text part of a marketing message is routinely empty, a stub, or a
     * link farm, and a business reading customer mail would see a worse version
     * of every message for a safety property {@see BodySanitiser} already
     * provides. Blocks are still produced. The raw HTML part is carried as
     * `$sourceHtml` so {@see HtmlEmailSanitiser} can build a Gmail-close
     * document; it is not published.
     *
     * @param  array<string, mixed>  $payload
     */
    public function detail(array $payload): MessageDetail
    {
        $summary = $this->summary($payload);
        $body    = $payload['payload'] ?? [];

        $html = $this->partBody($body, 'text/html');
        $text = $this->partBody($body, 'text/plain');

        $blocks = match (true) {
            $html !== null => $this->sanitiser->sanitise($html),
            $text !== null => $this->sanitiser->sanitiseText($text),

            /*
             * Neither part is there. A real case rather than a defensive one:
             * a message can be nothing but an attachment. Saying so beats an
             * empty panel, which reads as a fault.
             */
            default => [Block::of(
                BlockKind::Notice,
                'This message has no readable text — it is probably an attachment on its own.',
            )],
        };

        return new MessageDetail(
            summary: $summary,
            blocks: $blocks,
            attachments: $this->attachments($body),
            imagesBlocked: $this->mentionsBlockedImage($blocks),
            sourceHtml: $html,
        );
    }

    // ── headers ──────────────────────────────────────────────────

    /**
     * Headers as a lower-cased map.
     *
     * Lower-cased because header names are case-insensitive and Gmail returns
     * them as the sender wrote them — `From`, `FROM` and `from` all occur, and a
     * map keyed on the raw name would miss two thirds of the time.
     *
     * A repeated header keeps the FIRST occurrence. Two `Subject` lines is a
     * known spoofing trick: some clients show the first and some the last, and
     * choosing deliberately beats letting array order decide.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function headers(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['payload']['headers'] ?? []) as $header) {
            if (! is_array($header) || ! isset($header['name'])) {
                continue;
            }

            $name = strtolower((string) $header['name']);

            $out[$name] ??= (string) ($header['value'] ?? '');
        }

        return $out;
    }

    /**
     * `Bob Smith <bob@example.com>` into its two halves.
     *
     * ── The display name is not to be trusted, and is kept anyway ─
     *
     * `"info@yourbank.com" <thief@example.net>` is the oldest trick in mail, and
     * the answer is not to drop the name — every legitimate message has one and
     * a list of bare addresses is unreadable. The answer is that BOTH are
     * published as separate fields, so a client can draw the address beside the
     * name rather than trusting the name to be one. That is a decision the wire
     * shape makes for the client rather than one the client has to remember.
     *
     * @return array{0: string, 1: string}
     */
    private function splitAddress(string $raw): array
    {
        $raw = $this->line($raw);

        if (preg_match('/^(.*?)<([^>]*)>\s*$/', $raw, $found) === 1) {
            $name = trim($found[1], " \t\"'");

            return [$name, trim($found[2])];
        }

        // A bare address, which is legal and common from automated senders.
        return ['', trim($raw)];
    }

    /** @return string[] */
    private function addressList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $one) {
            [$name, $address] = $this->splitAddress($one);

            if ($address !== '') {
                $out[] = $name === '' ? $address : "{$name} <{$address}>";
            }
        }

        return $out;
    }

    /**
     * When it was sent.
     *
     * `internalDate` is preferred over the `Date:` header, and that is the
     * security-flavoured choice rather than the convenient one: `Date:` is
     * written by the sender and can say anything, including a date in 2099 —
     * which sorts a spam message permanently to the top of somebody's inbox.
     * `internalDate` is when Google received it, and the sender cannot set it.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function sentAt(array $payload, array $headers): ?Carbon
    {
        if (isset($payload['internalDate']) && is_numeric($payload['internalDate'])) {
            // Milliseconds since the epoch, as a STRING in the JSON — it is
            // larger than a 32-bit int and Google sends it quoted for that
            // reason. intdiv on a float would lose precision; this does not.
            return Carbon::createFromTimestampMs((int) $payload['internalDate']);
        }

        $date = $headers['date'] ?? '';

        if (trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Throwable) {
            // A malformed Date: header is a message with no date, not an error.
            return null;
        }
    }

    // ── parts ────────────────────────────────────────────────────

    /**
     * The decoded body of the first part with this MIME type, at any depth.
     *
     * Recursive because Gmail nests: a message with an attachment and both body
     * formats is `multipart/mixed` containing `multipart/alternative` containing
     * the two text parts, and a one-level scan finds neither. Depth is bounded
     * by the payload Google returns rather than by a counter here — but a
     * deliberately deep structure is somebody else's problem to send and ours to
     * survive, so the walk is iterative in shape and cannot recurse without a
     * `parts` array to descend into.
     *
     * @param  array<string, mixed>  $part
     */
    private function partBody(array $part, string $mime): ?string
    {
        if (strtolower((string) ($part['mimeType'] ?? '')) === $mime) {
            $data = $part['body']['data'] ?? null;

            if (is_string($data) && $data !== '') {
                return $this->base64Url($data);
            }
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $found = $this->partBody($child, $mime);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Gmail encodes bodies base64url and without padding.
     *
     * `strtr` rather than a str_replace pair for the two swapped characters, and
     * the padding is restored by length — `base64_decode` in strict mode refuses
     * an unpadded string, and in non-strict mode it silently returns something
     * different from what was sent.
     */
    private function base64Url(string $data): string
    {
        $translated = strtr($data, '-_', '+/');
        $padding    = strlen($translated) % 4;

        if ($padding > 0) {
            $translated .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($translated, strict: false);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Attachments LISTED, never fetched — decision 2.
     *
     * A part is an attachment when it has a filename. `attachmentId` is
     * deliberately NOT published: it is the handle a download would use, and
     * publishing it would invite a client to build a download this application
     * has not decided how to serve safely.
     *
     * @param  array<string, mixed>  $part
     * @return array<int, array{name: string, mime: string, bytes: int}>
     */
    private function attachments(array $part): array
    {
        $out = [];

        $filename = (string) ($part['filename'] ?? '');

        if ($filename !== '') {
            $out[] = [
                // The filename is the sender's text. It is published as a plain
                // string that a client draws as a label — never used to open,
                // save or name anything, so a `../../etc/passwd` in it is a
                // funny-looking label and nothing more.
                'name'  => $this->line($filename),
                'mime'  => $this->line((string) ($part['mimeType'] ?? 'application/octet-stream')),
                'bytes' => (int) ($part['body']['size'] ?? 0),
            ];
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (is_array($child)) {
                $out = [...$out, ...$this->attachments($child)];
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $part */
    private function hasAttachments(array $part): bool
    {
        return $this->attachments($part) !== [];
    }

    /**
     * The MIME part whose Content-ID is this cid, at any depth.
     *
     * @param  array<string, mixed>  $part
     * @return array<string, mixed>|null
     */
    public function partByContentId(array $part, string $contentId): ?array
    {
        $want = strtolower(trim($contentId, ' <>'));

        foreach ((array) ($part['headers'] ?? []) as $header) {
            if (! is_array($header)) {
                continue;
            }

            $name = strtolower((string) ($header['name'] ?? ''));

            if ($name !== 'content-id') {
                continue;
            }

            $have = strtolower(trim((string) ($header['value'] ?? ''), ' <>'));

            if ($have !== '' && $have === $want) {
                return $part;
            }
        }

        foreach ((array) ($part['parts'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            $found = $this->partByContentId($child, $contentId);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param  Block[]  $blocks */
    private function mentionsBlockedImage(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block->kind === BlockKind::BlockedImage) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line of somebody else's text, made safe to put in a list row.
     *
     * Control characters out and whitespace collapsed — the same treatment
     * BodySanitiser gives body text, for the same reason: a subject line
     * containing U+202E reverses everything after it on screen, and a subject
     * containing a newline is how a header is smuggled into anything that later
     * writes one.
     */
    private function line(string $value): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
