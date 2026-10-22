<?php

namespace Tests\Feature;

use App\Models\Mailbox;
use App\Support\Email\ReplyComposer;
use Tests\TestCase;

/**
 * Composing a NEW message — the half of the send path that needs no provider.
 *
 * ── Why this is separate from the reply tests ────────────────────
 *
 * The reply path's dangerous input arrives from outside: the subject comes off
 * the message being answered, so it is attacker-controlled by construction and
 * everybody treats it that way. Here the dangerous input is typed by a
 * signed-in colleague, which reads as safer and is not. A To field is a header
 * value like any other, and "our own staff would not" is not an access control.
 *
 * So every injection case below is written from the inside: a colleague, not a
 * stranger, putting a newline where a newline ends a header.
 *
 * ── Every test carries a control ─────────────────────────────────
 *
 * A composer that returned an empty string would pass every "the payload cannot
 * start a header" assertion here. Each case also asserts the legitimate part
 * survived, so a green run means the message was built AND was safe, rather
 * than that nothing was built.
 */
class EmailComposeTest extends TestCase
{
    private function inbox(): Mailbox
    {
        // Unsaved on purpose: composing touches no database, and a test that
        // needed one would be asserting something other than what it claims.
        return new Mailbox([
            'address'      => 'info@a-1chimney.com',
            'display_name' => 'Info',
        ]);
    }

    private function composer(): ReplyComposer
    {
        return new ReplyComposer;
    }

    /**
     * The real invariant, and it is NOT "the payload disappears".
     *
     * {@see ReplyComposer} replaces CR and LF with a SPACE rather than deleting
     * them, deliberately: `Invoice<CR><LF>Bcc:` becomes one obviously-odd header
     * line instead of `InvoiceBcc:`, which reads as a legitimate word. So the
     * text survives, visibly, and what must be true is that it cannot BEGIN a
     * header line — because a header is only a header at the start of one.
     *
     * Asserting absence instead would assert a behaviour the composer does not
     * have and should not have. That mistake was made once while writing this
     * file: the assertion went red against correct code, which is the failure
     * mode worth leaving a note about.
     */
    private function assertNoHeaderLineStartsWith(string $message, string $name): void
    {
        [$headers] = explode("\r\n\r\n", $message, 2);

        foreach (explode("\r\n", $headers) as $line) {
            $this->assertStringStartsNotWith(
                $name,
                $line,
                "\"{$line}\" is a header the sender was not entitled to set.",
            );
        }
    }

    public function test_a_new_message_goes_out_as_the_inbox_and_not_as_a_person(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Your annual sweep',
            'Morning Bob',
        );

        $this->assertStringContainsString('From: Info <info@a-1chimney.com>', $message);
        $this->assertStringContainsString('To: bob@example.com', $message);
        $this->assertStringContainsString('Subject: Your annual sweep', $message);
        $this->assertStringContainsString("\r\n\r\nMorning Bob", $message);
    }

    /**
     * The header/body separator is one CRLF CRLF, not two CRs.
     *
     * Pinned because it was wrong once: an editing slip put CR CR LF between
     * every header, which no assertion in this file noticed until the body
     * separator was checked by byte. A malformed separator does not throw — it
     * produces a message some servers accept and others silently mangle, which
     * is the worst way for this to be wrong.
     */
    public function test_lines_end_with_one_crlf_each(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Sweep',
            'Morning',
        );

        $this->assertStringNotContainsString("\r\r", $message);
        $this->assertStringNotContainsString("\n\n", $message);
        $this->assertSame(1, substr_count($message, "\r\n\r\n"));
    }

    /**
     * A new message is not under anything.
     *
     * Inventing threading headers would file it beneath whatever conversation
     * happened to be open in the recipient's mail program, which is worse than
     * untidy — it buries a fresh message under an unrelated old one.
     */
    public function test_a_new_message_carries_no_threading_headers(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Your annual sweep',
            'Morning Bob',
        );

        $this->assertStringNotContainsStringIgnoringCase('In-Reply-To:', $message);
        $this->assertStringNotContainsStringIgnoringCase('References:', $message);

        // The control: the message was built at all.
        $this->assertStringContainsString('Subject: Your annual sweep', $message);
    }

    public function test_every_recipient_reaches_the_to_header(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com', 'jan@example.com', 'sam@example.com'],
            'Scheduling',
            'Three of you',
        );

        $this->assertStringContainsString('To: bob@example.com, jan@example.com, sam@example.com', $message);
    }

    /**
     * THE HOLE, entered through the recipient field.
     *
     * A colleague typing a newline into To would end the To header early and
     * make everything after it a new header — silently copying the business's
     * mail to an address nobody in the business chose.
     */
    public function test_a_newline_in_a_recipient_cannot_open_a_new_header(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ["bob@example.com\r\nBcc: everyone@competitor.example", 'jan@example.com'],
            'Scheduling',
            'Morning',
        );

        $this->assertNoHeaderLineStartsWith($message, 'Bcc');

        // Folded into the To line as visible nonsense rather than deleted.
        $this->assertStringContainsString(
            'To: bob@example.com Bcc: everyone@competitor.example, jan@example.com',
            $message,
        );

        // The controls: the real recipients are both still there.
        $this->assertStringContainsString('bob@example.com', $message);
        $this->assertStringContainsString('jan@example.com', $message);
    }

    public function test_a_newline_in_the_subject_cannot_open_a_new_header(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            "Invoice\r\nBcc: everyone@competitor.example",
            'Morning',
        );

        $this->assertNoHeaderLineStartsWith($message, 'Bcc');

        // The control: the legitimate half survived, folded onto one line.
        $this->assertStringContainsString('Subject: Invoice Bcc: everyone@competitor.example', $message);
    }

    /**
     * A bare CR is enough on its own — some mail servers accept it as a line
     * ending, so scrubbing only CRLF would leave the hole open.
     */
    public function test_a_bare_carriage_return_is_scrubbed_too(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ["bob@example.com\rBcc: everyone@competitor.example"],
            'Invoice',
            'Morning',
        );

        $this->assertNoHeaderLineStartsWith($message, 'Bcc');
        $this->assertStringContainsString('bob@example.com', $message);
    }

    /**
     * Some servers treat a subjectless message as suspicious, and a business's
     * mail landing in spam because somebody left the field blank is a support
     * call rather than a cosmetic problem.
     */
    public function test_an_empty_subject_becomes_no_subject_rather_than_nothing(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            '   ',
            'Morning',
        );

        $this->assertStringContainsString('Subject: (no subject)', $message);
    }

    /**
     * `Re:` belongs to the reply path and must not leak into this one — a new
     * message announcing itself as an answer to something is a lie about the
     * conversation, and the two subject rules live in the same class.
     */
    public function test_a_new_message_is_never_prefixed_re(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Your annual sweep',
            'Morning',
        );

        $this->assertStringNotContainsString('Subject: Re:', $message);
    }

    /**
     * The body is NOT scrubbed, and must not be — scrubbing it would destroy
     * the paragraphs somebody typed. It is safe because the blank line above it
     * already ended the headers.
     */
    public function test_a_body_may_contain_anything_because_headers_have_ended(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Notes',
            "First line\nBcc: this is just text\nLast line",
        );

        [$headers, $body] = explode("\r\n\r\n", $message, 2);

        $this->assertStringNotContainsStringIgnoringCase('Bcc:', $headers);
        $this->assertStringContainsString('Bcc: this is just text', $body);
        $this->assertStringContainsString('Last line', $body);
        $this->assertNoHeaderLineStartsWith($message, 'Bcc');
    }

    // ── attachments ──────────────────────────────────────────────

    /** @return array{name: string, mime: string, bytes: string} */
    private function file(string $name = 'sweep.pdf', string $mime = 'application/pdf', string $bytes = '%PDF-1.4 report'): array
    {
        return ['name' => $name, 'mime' => $mime, 'bytes' => $bytes];
    }

    public function test_a_message_with_no_files_stays_a_plain_text_message(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Sweep',
            'Morning',
        );

        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $message);
        $this->assertStringNotContainsStringIgnoringCase('multipart', $message);
        $this->assertStringNotContainsString('boundary', $message);
    }

    public function test_a_file_arrives_as_its_own_part_named_and_typed(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Report',
            'See attached.',
            [$this->file()],
        );

        $this->assertMatchesRegularExpression(
            '/Content-Type: multipart\/mixed; boundary="ezbizi-[0-9a-f]{32}"/',
            $message,
        );

        $this->assertStringContainsString('Content-Type: application/pdf', $message);
        $this->assertStringContainsString(
            'Content-Disposition: attachment; filename="sweep.pdf"',
            $message,
        );
        $this->assertStringContainsString('Content-Transfer-Encoding: base64', $message);

        // The typed words survive alongside the file — a multipart message that
        // carries the attachment and drops the sentence is the failure that
        // looks fine in a file list.
        $this->assertStringContainsString('See attached.', $message);
    }

    /**
     * THE ONE CORRECTNESS RULE OF MULTIPART: the boundary must not occur
     * inside a part.
     *
     * A fixed boundary breaks the first time somebody attaches a saved copy of
     * a message this composer built, because that file's contents ARE boundary
     * lines. The reader then sees the message truncated at the collision, with
     * no error at either end.
     */
    public function test_the_boundary_is_different_every_time(): void
    {
        $composer = $this->composer();
        $inbox    = $this->inbox();

        $first  = $composer->composeNew($inbox, ['b@example.com'], 'A', 'x', [$this->file()]);
        $second = $composer->composeNew($inbox, ['b@example.com'], 'A', 'x', [$this->file()]);

        preg_match('/boundary="([^"]+)"/', $first, $a);
        preg_match('/boundary="([^"]+)"/', $second, $b);

        $this->assertNotSame($a[1], $b[1]);

        // And long enough that a collision is not a thing that happens.
        $this->assertSame(32, strlen(str_replace('ezbizi-', '', $a[1])));
    }

    /**
     * A filename is a header value, and it comes from whoever picked the file.
     */
    public function test_a_newline_in_a_filename_cannot_open_a_new_header(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Report',
            'Morning',
            [$this->file(name: "quote.pdf\r\nBcc: everyone@competitor.example")],
        );

        foreach (explode("\r\n", $message) as $line) {
            $this->assertStringStartsNotWith('Bcc', $line);
        }

        // The control: the real filename is still on the part.
        $this->assertStringContainsString('quote.pdf', $message);
    }

    /**
     * Binary survives the trip, byte for byte.
     *
     * Base64 rather than 8bit precisely so that a PDF is not corrupted by one
     * hop that does not speak the extension — so the test decodes the part back
     * and compares, rather than checking that some base64 is present.
     */
    public function test_binary_content_round_trips_exactly(): void
    {
        $bytes = random_bytes(2048);

        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Photo',
            'Here',
            [$this->file(name: 'photo.png', mime: 'image/png', bytes: $bytes)],
        );

        // The part after the base64 header, up to the closing boundary.
        preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $message, $m);

        $this->assertNotEmpty($m, 'No base64 part was produced.');
        $this->assertSame($bytes, base64_decode(str_replace("\r\n", '', $m[1]), true));
    }

    /**
     * RFC 2045 caps an ENCODED line at 76 characters, and servers enforce it.
     *
     * Only the encoded part: the rule is about base64 content lines, not about
     * headers, which live under RFC 5322's own limit of 998. The first version
     * of this test checked every line in the message and failed on the
     * `Content-Type: multipart/mixed; boundary="…"` header at 81 characters —
     * a correct header, and a wrong assertion.
     */
    public function test_no_encoded_line_runs_past_seventy_six_characters(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Photo',
            'Here',
            [$this->file(name: 'photo.png', mime: 'image/png', bytes: random_bytes(4096))],
        );

        preg_match('/Content-Transfer-Encoding: base64\r\n\r\n(.*?)\r\n--/s', $message, $m);

        $this->assertNotEmpty($m, 'No base64 part was produced.');

        $lines = explode("\r\n", $m[1]);

        // The control: a single unwrapped line would trivially pass a
        // "every line is short" check if the part were empty, so assert there
        // is real content wrapped across several lines.
        $this->assertGreaterThan(10, count($lines));

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(76, strlen($line));
        }
    }

    public function test_several_files_each_get_their_own_part(): void
    {
        $message = $this->composer()->composeNew(
            $this->inbox(),
            ['bob@example.com'],
            'Two things',
            'Both attached.',
            [$this->file(name: 'one.pdf'), $this->file(name: 'two.pdf')],
        );

        preg_match('/boundary="([^"]+)"/', $message, $b);

        $this->assertStringContainsString('filename="one.pdf"', $message);
        $this->assertStringContainsString('filename="two.pdf"', $message);

        // Three opening delimiters — the text part and two files — plus the
        // closing one. A message that dropped a part would still contain both
        // filenames if they shared a part, so the count is what proves it.
        $this->assertSame(3, substr_count($message, '--'.$b[1]."\r\n"));
        $this->assertStringEndsWith('--'.$b[1].'--', $message);
    }

    /** Gmail wants the same bytes, base64url and unpadded. */
    public function test_the_gmail_form_is_the_same_message_encoded(): void
    {
        $composer = $this->composer();
        $inbox    = $this->inbox();

        $raw     = $composer->composeNew($inbox, ['bob@example.com'], 'Sweep', 'Morning');
        $encoded = $composer->composeNewForGmail($inbox, ['bob@example.com'], 'Sweep', 'Morning');

        $this->assertSame($raw, base64_decode(strtr($encoded, '-_', '+/'), true));
        $this->assertStringNotContainsString('=', $encoded);
        $this->assertStringNotContainsString('+', $encoded);
        $this->assertStringNotContainsString('/', $encoded);
    }

    /** Reply-all / copies (design 137): To and Cc each guarded, so a typed Cc cannot add a header. */
    public function test_a_reply_with_copies_carries_to_and_cc_and_cannot_inject(): void
    {
        $original = new \App\Support\Email\MessageSummary(
            id: 'm-1', threadId: 't-1', subject: 'Quote', fromName: 'Bob', fromAddress: 'bob@example.com',
            to: [], sentAt: null, snippet: '',
        );

        $message = $this->composer()->compose(
            $this->inbox(), $original, [], 'Hi all',
            ['bob@example.com', 'ann@example.com'],
            ["carl@example.com\r\nBcc: spy@evil.test"],
        );

        $this->assertStringContainsString('To: bob@example.com, ann@example.com', $message);
        $this->assertStringContainsString('Cc: carl@example.com', $message);
        $this->assertNoHeaderLineStartsWith($message, 'Bcc');
    }
}
