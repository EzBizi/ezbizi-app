<?php

namespace Tests\Feature;

use App\Support\Email\BlockKind;
use App\Support\Email\BodySanitiser;
use App\Support\Email\ImapMessageMapper;
use Tests\TestCase;

/**
 * IMAP's own descriptions of a message, turned into the module's shapes.
 *
 * ── Why these need no server ─────────────────────────────────────
 *
 * Because {@see ImapMessageMapper} is deliberately the half of the provider
 * that has no socket in it — the same split {@see \App\Support\Email\GmailMessageMapper}
 * makes, and for the same stated reason: the parsing is where the bugs are, and
 * parsing is testable without a mail account. What the tokens LOOK like coming
 * off the wire is settled in {@see ImapTransportTest} against a real server; what
 * they MEAN is settled here.
 */
class ImapMessageMapperTest extends TestCase
{
    private ImapMessageMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new ImapMessageMapper(new BodySanitiser);
    }

    /**
     * An ENVELOPE, as the tokeniser hands it over.
     *
     * @return array<int, mixed>
     */
    private function envelope(): array
    {
        return [
            'Mon, 3 Aug 2026 09:14:00 +0100',
            '=?UTF-8?Q?Rechnung_f=C3=BCr_M=C3=A4rz?=',
            [['Ada Lovelace', null, 'ada', 'example.com']],   // from
            [['Ada Lovelace', null, 'ada', 'example.com']],   // sender
            [['Ada Lovelace', null, 'ada', 'example.com']],   // reply-to
            [[null, null, 'info', 'ezbizi.test'], [null, null, 'accounts', 'ezbizi.test']],
            null, null, null, '<msg-1@example.com>',
        ];
    }

    public function test_an_envelope_becomes_a_summary(): void
    {
        $summary = $this->mapper->summary(41, [
            'envelope' => $this->envelope(),
            'flags'    => ['\\Seen'],
        ]);

        $this->assertSame('41', $summary->id);
        $this->assertSame('Ada Lovelace', $summary->fromName);
        $this->assertSame('ada@example.com', $summary->fromAddress);
        $this->assertSame(['info@ezbizi.test', 'accounts@ezbizi.test'], $summary->to);
        $this->assertSame('2026-08-03', $summary->sentAt?->toDateString());

        // \Seen at the server means NOT unread at the provider. EzBizi's own
        // seen-marks are a different fact and live in `message_seen`.
        $this->assertFalse($summary->unreadAtProvider);

        /*
         * The subject arrived RFC 2047 encoded, which is how every non-ASCII
         * subject arrives. Left alone, the message list would show
         * `=?UTF-8?Q?Rechnung_f=C3=BCr...` where the subject should be.
         */
        $this->assertSame('Rechnung für März', $summary->subject);
    }

    /**
     * A message with no threading notion gets a null thread, not a guess.
     *
     * Grouping by subject is the tempting substitute and it is wrong: two
     * unrelated messages called "Invoice" are not a conversation.
     */
    public function test_a_summary_does_not_invent_a_thread(): void
    {
        $summary = $this->mapper->summary(41, ['envelope' => $this->envelope()]);

        $this->assertNull($summary->threadId);
        $this->assertTrue($summary->unreadAtProvider, 'A message with no \\Seen flag is unread at the provider.');
    }

    /**
     * Plain text is preferred over HTML where a message offers both.
     *
     * The cheaper branch and the safer one are the same branch, which is worth
     * having on purpose rather than by luck.
     */
    public function test_the_plain_part_is_preferred_over_the_html_one(): void
    {
        $structure = [
            ['TEXT', 'PLAIN', ['CHARSET', 'utf-8'], null, null, '7BIT', '24', '1'],
            ['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, 'BASE64', '512', '9'],
            'ALTERNATIVE',
        ];

        $part = $this->mapper->textPart($structure);

        $this->assertSame('1', $part['part']);
        $this->assertSame('plain', $part['subtype']);
    }

    /** A message that only offers HTML still has a body. */
    public function test_an_html_only_message_still_has_a_body_part(): void
    {
        $part = $this->mapper->textPart(['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, '7BIT', '99', '4']);

        $this->assertSame('1', $part['part']);
        $this->assertSame('html', $part['subtype']);
    }

    /**
     * Attachments are LISTED from the structure, and never fetched.
     *
     * Both spellings are read: `Content-Disposition: attachment; filename=` and
     * the older `Content-Type: ...; name=`. A message carrying only the second
     * is still a message with a file in it, and treating it as body text would
     * put base64 on somebody's screen.
     */
    public function test_attachments_are_listed_from_the_structure(): void
    {
        $structure = [
            ['TEXT', 'PLAIN', ['CHARSET', 'utf-8'], null, null, '7BIT', '24', '1'],
            ['APPLICATION', 'PDF', ['NAME', 'invoice.pdf'], null, null, 'BASE64', '204800', null,
                ['attachment', ['FILENAME', 'invoice.pdf']]],
            ['IMAGE', 'PNG', ['NAME', 'logo.png'], null, null, 'BASE64', '4096'],
            'MIXED',
        ];

        $attachments = $this->mapper->attachments($structure);

        $this->assertCount(2, $attachments);
        $this->assertSame(['name' => 'invoice.pdf', 'mime' => 'application/pdf', 'bytes' => 204800], $attachments[0]);
        $this->assertSame('logo.png', $attachments[1]['name']);

        // And the .pdf must not be mistaken for the body.
        $this->assertSame('1', $this->mapper->textPart($structure)['part']);
    }

    /**
     * A signature logo is part of the body, not a file (Eve 11-11: ten
     * image0NN.png buried a State Farm thread). Inline, or a Content-ID not
     * marked attachment, is not listed; an attachment with a Content-ID is.
     */
    public function test_inline_pictures_are_not_attachments(): void
    {
        $structure = [
            ['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, '7BIT', '24', '1'],
            ['IMAGE', 'PNG', ['NAME', 'image001.png'], '<image001.png@01DC>', null, 'BASE64', '9000', null,
                ['inline', ['FILENAME', 'image001.png']]],
            ['IMAGE', 'PNG', ['NAME', 'image002.png'], '<image002.png@01DC>', null, 'BASE64', '600'],
            ['APPLICATION', 'PDF', ['NAME', 'claim.pdf'], '<claim@x>', null, 'BASE64', '204800', null,
                ['attachment', ['FILENAME', 'claim.pdf']]],
            'RELATED',
        ];

        $this->assertSame(['claim.pdf'], array_column($this->mapper->attachments($structure), 'name'));
    }

    /**
     * A text part that is an ATTACHMENT is not the body.
     *
     * A message with a .txt file attached would otherwise have the attachment
     * drawn as its message.
     */
    public function test_an_attached_text_file_is_not_treated_as_the_body(): void
    {
        $structure = [
            ['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, '7BIT', '99', '4'],
            ['TEXT', 'PLAIN', ['NAME', 'notes.txt'], null, null, '7BIT', '900', '30',
                ['attachment', ['FILENAME', 'notes.txt']]],
            'MIXED',
        ];

        $this->assertSame('1', $this->mapper->textPart($structure)['part']);
        $this->assertSame('notes.txt', $this->mapper->attachments($structure)[0]['name']);
    }

    /** Nested multipart gets dotted part numbers, which is how IMAP addresses them. */
    public function test_a_nested_multipart_produces_a_dotted_part_number(): void
    {
        $structure = [
            [
                ['TEXT', 'PLAIN', ['CHARSET', 'utf-8'], null, null, '7BIT', '24', '1'],
                ['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, '7BIT', '99', '4'],
                'ALTERNATIVE',
            ],
            ['APPLICATION', 'PDF', ['NAME', 'x.pdf'], null, null, 'BASE64', '10', null, ['attachment', ['FILENAME', 'x.pdf']]],
            'MIXED',
        ];

        $this->assertSame('1.1', $this->mapper->textPart($structure)['part']);
    }

    // ── the body, and the one rule about it ──────────────────────

    /**
     * HTML never reaches the caller — it comes back as blocks.
     */
    public function test_an_html_body_comes_back_as_blocks_with_nothing_executable_in_it(): void
    {
        $html = '<p>Hello</p><script>alert(1)</script>'
            .'<img src="http://169.254.169.254/latest/meta-data/" alt="pixel">'
            .'<a href="javascript:alert(2)">click</a>';

        $part = ['part' => '1', 'subtype' => 'html', 'encoding' => '7bit', 'charset' => 'utf-8'];

        $detail = $this->mapper->detail(
            $this->mapper->summary(1, ['envelope' => $this->envelope()]),
            ['bodystructure' => ['TEXT', 'HTML', ['CHARSET', 'utf-8'], null, null, '7BIT', strlen($html), '4']],
            $html,
            $part,
        );

        $published = json_encode($detail->toArray());

        $this->assertStringNotContainsString('<script', $published);
        $this->assertStringNotContainsString('alert(1)', $published);
        $this->assertStringNotContainsString('169.254.169.254', $published);
        $this->assertStringNotContainsString('javascript:', $published);

        $this->assertSame('Hello', $detail->blocks[0]->text());

        // Said out loud rather than silently dropped: a message that quietly
        // loses its pictures looks broken.
        $this->assertTrue($detail->imagesBlocked);
        $this->assertSame(BlockKind::BlockedImage, $detail->blocks[1]->kind);
    }

    /**
     * base64 is undone before the character set is converted, never after.
     *
     * The order is not interchangeable: base64 is an encoding OF the bytes and
     * the bytes are what carry the character set, so converting first converts
     * the base64 alphabet — which is ASCII — and silently succeeds while
     * producing nothing.
     */
    public function test_a_base64_latin1_body_is_decoded_in_the_right_order(): void
    {
        $latin1 = (string) mb_convert_encoding('Grüße aus München', 'ISO-8859-1', 'UTF-8');

        $detail = $this->mapper->detail(
            $this->mapper->summary(1, ['envelope' => $this->envelope()]),
            [],
            base64_encode($latin1),
            ['part' => '1', 'subtype' => 'plain', 'encoding' => 'base64', 'charset' => 'iso-8859-1'],
        );

        $this->assertSame('Grüße aus München', $detail->blocks[0]->text());
    }

    public function test_a_quoted_printable_body_is_decoded(): void
    {
        $detail = $this->mapper->detail(
            $this->mapper->summary(1, ['envelope' => $this->envelope()]),
            [],
            'Preis: 100=C2=A0EUR',
            ['part' => '1', 'subtype' => 'plain', 'encoding' => 'quoted-printable', 'charset' => 'utf-8'],
        );

        $this->assertStringContainsString('100', $detail->blocks[0]->text());
        $this->assertStringContainsString('EUR', $detail->blocks[0]->text());
    }

    /**
     * A part LABELLED utf-8 that is not valid utf-8 still produces readable text.
     *
     * Broken bytes reaching the sanitiser make PHP's DOM parser drop content, so
     * a message would silently lose text rather than showing it imperfectly.
     */
    public function test_a_body_that_lies_about_being_utf8_is_still_readable(): void
    {
        $detail = $this->mapper->detail(
            $this->mapper->summary(1, ['envelope' => $this->envelope()]),
            [],
            "Caf\xE9 open",   // Latin-1 bytes wearing a utf-8 label
            ['part' => '1', 'subtype' => 'plain', 'encoding' => '7bit', 'charset' => 'utf-8'],
        );

        $this->assertStringContainsString('Caf', $detail->blocks[0]->text());
        $this->assertStringContainsString('open', $detail->blocks[0]->text());
        $this->assertTrue(mb_check_encoding($detail->blocks[0]->text(), 'UTF-8'));
    }

    // ── the snippet ──────────────────────────────────────────────

    /**
     * A snippet is short, one line, and sanitised like everything else.
     *
     * Reaching for `strip_tags()` here would be adding a second, worse sanitiser
     * beside {@see BodySanitiser} and running it over the same hostile input.
     */
    public function test_a_snippet_is_short_and_carries_no_markup(): void
    {
        $html = '<p>Hello there, about the invoice you sent last week</p>'
            .'<script>alert(1)</script>'
            .'<p>'.str_repeat('padding words ', 60).'</p>';

        $snippet = $this->mapper->snippet($html, ['part' => '1', 'subtype' => 'html', 'encoding' => '7bit', 'charset' => 'utf-8']);

        $this->assertStringNotContainsString('<', $snippet);
        $this->assertStringNotContainsString('alert(1)', $snippet);
        $this->assertStringContainsString('Hello there', $snippet);
        $this->assertLessThanOrEqual(ImapMessageMapper::SNIPPET_CHARACTERS + 1, mb_strlen($snippet));
    }

    public function test_an_empty_part_produces_an_empty_snippet(): void
    {
        $this->assertSame('', $this->mapper->snippet('', ['part' => '1', 'subtype' => 'plain', 'encoding' => '7bit', 'charset' => 'utf-8']));
    }
}
