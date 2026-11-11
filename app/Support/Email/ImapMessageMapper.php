<?php

namespace App\Support\Email;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * What an IMAP server answers, turned into the shapes the rest of the module
 * already speaks.
 *
 * The counterpart of {@see GmailMessageMapper}, and it exists for the same
 * stated reason: it is the half of the provider that can be exercised WITHOUT a
 * mail account, so the parsing — which is where the bugs are — is testable on
 * its own.
 *
 * ── Why the structure is read instead of the message ─────────────
 *
 * The obvious way to read a message is to download it whole and parse the MIME.
 * That needs a MIME parser, and it means pulling a twenty-megabyte attachment
 * across the wire to find the two lines of text somebody actually wrote.
 *
 * IMAP already did the parsing. `BODYSTRUCTURE` is the server's own description
 * of the message tree — every part, its type, its encoding, its size and its
 * part number — and `BODY.PEEK[1.2]` fetches exactly one of them. So
 * {@see textPart} walks the structure to decide WHICH part is the body, and
 * only that part is downloaded. Attachments are LISTED from the structure and
 * never fetched, which is what {@see MessageDetail} says happens and is now also
 * what happens.
 *
 * That is also why no MIME-parsing dependency was added: the parsing this module
 * needs was already done at the far end, and asking for its result is one
 * command.
 */
final class ImapMessageMapper
{
    /**
     * How much of a message the list shows.
     *
     * Characters, not kilobytes — {@see MessageSummary} says so, and the reason
     * is that this string is what {@see HeaderCache} may briefly hold. A limit
     * measured in kilobytes is one somebody raises; a limit of a couple of
     * sentences stays a couple of sentences.
     */
    public const SNIPPET_CHARACTERS = 160;

    /**
     * How many bytes of a body are fetched to build one of those.
     *
     * Enough that 160 characters survive base64 (which inflates by a third) and
     * a quoted-printable escape or two, and small enough that a page of twenty
     * previews is a few kilobytes rather than a download.
     */
    public const SNIPPET_FETCH_BYTES = 1024;

    public function __construct(
        private readonly BodySanitiser $sanitiser,
    ) {}

    /**
     * One row of the list, from a FETCH of ENVELOPE and BODYSTRUCTURE.
     *
     * @param  array<string, mixed>  $fetched
     */
    public function summary(int $uid, array $fetched, string $snippet = ''): MessageSummary
    {
        $envelope = is_array($fetched['envelope'] ?? null) ? $fetched['envelope'] : [];
        $from     = $this->firstAddress($envelope[2] ?? null);

        return new MessageSummary(
            id: (string) $uid,

            /*
             * Null, and honestly so. Threading is the THREAD extension, which is
             * optional and absent from a great many servers, and the alternative
             * — grouping by subject — is a guess that puts two unrelated messages
             * called "Invoice" in one conversation. {@see MessageSummary} says
             * this field is "where the provider has such a notion"; a generic
             * IMAP server has none.
             */
            threadId: null,

            subject: $this->decodeHeader($this->stringOr($envelope[1] ?? null)),
            fromName: $this->decodeHeader($from['name']),
            fromAddress: $from['address'],
            to: $this->addresses($envelope[5] ?? null),
            sentAt: $this->date($this->stringOr($envelope[0] ?? null)),
            snippet: $snippet,
            hasAttachments: $this->attachments($fetched['bodystructure'] ?? null) !== [],
            unreadAtProvider: ! $this->hasFlag($fetched['flags'] ?? null, '\\Seen'),
        );
    }

    /**
     * The opened message — with the body already through {@see BodySanitiser}.
     *
     * @param  string  $body  the raw bytes of the ONE part {@see textPart} chose
     * @param  array{subtype: string, encoding: string, charset: string}|null  $part
     */
    public function detail(MessageSummary $summary, array $fetched, string $body, ?array $part): MessageDetail
    {
        $text = $part === null ? '' : $this->decodeBody($body, $part['encoding'], $part['charset']);

        /*
         * HTML through `sanitise`, plain text through `sanitiseText`, and the
         * choice is made from the part's own subtype rather than by sniffing the
         * bytes. Blocks are the fallback body. The HTML part is also kept as
         * `$sourceHtml` for Gmail-close display; it is not published until
         * {@see HtmlEmailSanitiser} has purified it.
         */
        $isHtml = $part !== null && strcasecmp($part['subtype'], 'html') === 0;
        $blocks = $isHtml
            ? $this->sanitiser->sanitise($text)
            : $this->sanitiser->sanitiseText($text);

        return new MessageDetail(
            summary: $summary,
            blocks: $blocks,
            attachments: $this->attachments($fetched['bodystructure'] ?? null),
            imagesBlocked: $this->mentionsBlockedImage($blocks),
            sourceHtml: $isHtml ? $text : null,
        );
    }

    /**
     * The first line or so of a message, for the list — never the body.
     *
     * ── Why it goes through the sanitiser too ────────────────────
     *
     * Because a snippet is text drawn on a screen, and the difference between it
     * and a body is length, not trust. Reaching for `strip_tags()` here would be
     * adding a second, worse sanitiser beside {@see BodySanitiser} — the exact
     * thing that must not happen — and it would be running that worse one over
     * the SAME hostile input. So the small bytes go through the same path the
     * large ones do, and what comes back is blocks whose text is read out.
     *
     * @param  array{subtype: string, encoding: string, charset: string}  $part
     */
    public function snippet(string $raw, array $part): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $text = $this->decodeBody($raw, $part['encoding'], $part['charset']);

        $blocks = strcasecmp($part['subtype'], 'html') === 0
            ? $this->sanitiser->sanitise($text)
            : $this->sanitiser->sanitiseText($text);

        $words = '';

        foreach ($blocks as $block) {
            $words = trim($words.' '.$block->text());

            if (strlen($words) >= self::SNIPPET_CHARACTERS) {
                break;
            }
        }

        $words = (string) preg_replace('/\s+/u', ' ', $words);

        return mb_strlen($words) > self::SNIPPET_CHARACTERS
            ? rtrim(mb_substr($words, 0, self::SNIPPET_CHARACTERS)).'…'
            : $words;
    }

    /**
     * Which part of the message is the thing somebody wrote.
     *
     * Plain text wins over HTML where a message offers both, which is the usual
     * shape of business mail — `multipart/alternative` with the same words
     * twice. Taking the plain part is not only cheaper: it is the one that never
     * needed sanitising in the first place, so the safest branch is also the
     * default one.
     *
     * @return array{part: string, subtype: string, encoding: string, charset: string}|null
     */
    public function textPart(mixed $structure): ?array
    {
        $candidates = [];

        $this->walk($structure, '', $candidates, attachments: false);

        foreach (['plain', 'html'] as $preferred) {
            foreach ($candidates as $candidate) {
                if (strcasecmp($candidate['subtype'], $preferred) === 0) {
                    return $candidate;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Every attachment, LISTED — name, claimed type and size. Never fetched.
     *
     * @return array<int, array{name: string, mime: string, bytes: int}>
     */
    public function attachments(mixed $structure): array
    {
        $found = [];

        $this->walk($structure, '', $found, attachments: true);

        return $found;
    }

    /**
     * Walk the structure tree, collecting either body candidates or attachments.
     *
     * ── One walker, because the tree is the same tree ────────────
     *
     * Two walkers would drift: a nesting shape handled in one and not the other
     * is a message whose attachment count disagrees with its body, and that is
     * exactly the sort of difference nobody notices until a customer says a PDF
     * is missing.
     *
     * @param  array<int, mixed>  $found
     */
    private function walk(mixed $structure, string $prefix, array &$found, bool $attachments, int $depth = 0): void
    {
        /*
         * A message can nest message/rfc822 parts inside each other, and a
         * hostile one can do it ten thousand times. The structure arrived inside
         * the socket's byte budget, so this cannot be enormous — but recursion
         * has its own limit and hitting it is a fatal error rather than a
         * refusal.
         */
        if (! is_array($structure) || $structure === [] || $depth > 20) {
            return;
        }

        // A list whose first element is itself a list is multipart: the children
        // are numbered 1..n, and the subtype follows them.
        if (is_array($structure[0] ?? null)) {
            $index = 0;

            foreach ($structure as $child) {
                if (! is_array($child)) {
                    break; // the children have ended; the rest is the subtype
                }

                $index++;

                $this->walk($child, $prefix === '' ? (string) $index : $prefix.'.'.$index, $found, $attachments, $depth + 1);
            }

            return;
        }

        // A leaf. Its part number is the prefix, or "1" for a message that is a
        // single part with no multipart wrapper at all.
        $part     = $prefix === '' ? '1' : $prefix;
        $type     = strtolower($this->stringOr($structure[0] ?? null));
        $subtype  = strtolower($this->stringOr($structure[1] ?? null));
        $params   = $this->params($structure[2] ?? null);
        $encoding = strtolower($this->stringOr($structure[5] ?? null));
        $filename = $this->filename($structure, $params);

        if ($attachments) {
            // Anything with a filename is an attachment, whatever its type,
            // except a part of the body: inline, or a Content-ID not marked as
            // an attachment -- a signature logo the HTML draws by cid (Eve 11-11).
            if ($filename !== '' && ! $this->isInline($structure)) {
                $found[] = [
                    'name'  => $this->decodeHeader($filename),
                    'mime'  => ($type === '' ? 'application' : $type).'/'.($subtype === '' ? 'octet-stream' : $subtype),
                    'bytes' => max(0, (int) $this->stringOr($structure[6] ?? null)),
                ];
            }

            return;
        }

        // A body candidate is text that is NOT an attachment: a message with a
        // .txt file attached must not have the attachment drawn as its body.
        if ($type === 'text' && $filename === '') {
            $found[] = [
                'part'     => $part,
                'subtype'  => $subtype,
                'encoding' => $encoding,
                'charset'  => strtolower($params['charset'] ?? 'utf-8'),
            ];
        }
    }

    /**
     * The transfer encoding undone, then the character set.
     *
     * In that order, and the order is not interchangeable: base64 is an encoding
     * OF the bytes, and the bytes are what carry the character set. Converting
     * first would be converting the base64 alphabet, which is ASCII, and would
     * silently succeed while producing nothing.
     */
    private function decodeBody(string $raw, string $encoding, string $charset): string
    {
        $decoded = match ($encoding) {
            'base64'           => (string) base64_decode($raw, strict: false),
            'quoted-printable' => quoted_printable_decode($raw),
            default            => $raw,
        };

        if ($charset === '' || in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], strict: true)) {
            /*
             * Still checked, not trusted. A part LABELLED utf-8 that is not
             * valid utf-8 would otherwise reach the sanitiser as broken bytes,
             * and PHP's DOM parser answers invalid encoding by dropping content
             * — so a message would silently lose text rather than showing it
             * imperfectly.
             */
            return mb_check_encoding($decoded, 'UTF-8')
                ? $decoded
                : (string) mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1');
        }

        try {
            // `//IGNORE` rather than a failure: a single bad byte in a long
            // message must not turn the whole thing into an error page.
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decoded);
        } catch (Throwable) {
            $converted = false;
        }

        return $converted === false
            ? (string) mb_convert_encoding($decoded, 'UTF-8', 'ISO-8859-1')
            : $converted;
    }

    /**
     * `=?UTF-8?B?...?=` turned back into words.
     *
     * Subjects and display names arrive encoded this way whenever they contain
     * anything but ASCII, which is most mail in most of the world. Left alone,
     * a message list shows `=?UTF-8?Q?Rechnung_f=C3=BCr?=` where the subject
     * should be.
     */
    private function decodeHeader(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded === false ? $value : $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function addresses(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];

        foreach ($list as $entry) {
            $address = $this->addressOf($entry);

            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * @return array{name: string, address: string}
     */
    private function firstAddress(mixed $list): array
    {
        $entry = is_array($list) ? ($list[0] ?? null) : null;

        return [
            'name'    => is_array($entry) ? $this->stringOr($entry[0] ?? null) : '',
            'address' => $this->addressOf($entry),
        ];
    }

    /** An IMAP address is `(name adl mailbox host)`. */
    private function addressOf(mixed $entry): string
    {
        if (! is_array($entry)) {
            return '';
        }

        $mailbox = $this->stringOr($entry[2] ?? null);
        $host    = $this->stringOr($entry[3] ?? null);

        if ($mailbox === '' || $host === '') {
            return $mailbox;
        }

        return $mailbox.'@'.$host;
    }

    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // A malformed Date header is common and harmless. A message with no
            // date is better than a page that will not load.
            return null;
        }
    }

    /**
     * A flat `("KEY" "value" "KEY" "value")` list as a lower-cased map.
     *
     * @return array<string, string>
     */
    private function params(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $params = [];

        for ($i = 0; $i + 1 < count($list); $i += 2) {
            if (is_string($list[$i])) {
                $params[strtolower($list[$i])] = $this->stringOr($list[$i + 1]);
            }
        }

        return $params;
    }

    /**
     * The filename, from either place a message may put it.
     *
     * `Content-Disposition: attachment; filename=` is the modern one and
     * `Content-Type: ...; name=` is what older senders use. Both are checked
     * because a message carrying only the second is still a message with a file
     * in it, and treating it as body text would put base64 on somebody's screen.
     *
     * @param  array<int, mixed>  $leaf
     * @param  array<string, string>  $params
     */
    private function filename(array $leaf, array $params): string
    {
        foreach (array_slice($leaf, 7) as $element) {
            if (! is_array($element) || ! is_string($element[0] ?? null)) {
                continue;
            }

            if (strcasecmp($element[0], 'attachment') === 0 || strcasecmp($element[0], 'inline') === 0) {
                $disposition = $this->params($element[1] ?? null);

                if (($disposition['filename'] ?? '') !== '') {
                    return $disposition['filename'];
                }
            }
        }

        return $params['name'] ?? '';
    }

    /** @param  array<int, mixed>  $leaf */
    private function isInline(array $leaf): bool
    {
        $disposition = '';

        foreach (array_slice($leaf, 7) as $element) {
            if (is_array($element) && is_string($element[0] ?? null)
                && in_array(strtolower($element[0]), ['attachment', 'inline'], true)) {
                $disposition = strtolower($element[0]);
            }
        }

        $contentId = $this->stringOr($leaf[3] ?? null);

        return $disposition === 'inline' || ($contentId !== '' && $disposition !== 'attachment');
    }

    private function hasFlag(mixed $flags, string $flag): bool
    {
        if (! is_array($flags)) {
            return false;
        }

        foreach ($flags as $value) {
            if (is_string($value) && strcasecmp($value, $flag) === 0) {
                return true;
            }
        }

        return false;
    }

    private function stringOr(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param  Block[]  $blocks
     */
    private function mentionsBlockedImage(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block->kind === BlockKind::BlockedImage) {
                return true;
            }
        }

        return false;
    }
}
