<?php

namespace Tests\Feature;

use App\Support\Email\BlockKind;
use App\Support\Email\BodySanitiser;
use App\Support\Email\GmailMessageMapper;
use Tests\TestCase;

/**
 * Gmail's JSON into this application's shapes — the one half of the Gmail
 * adapter that can be exercised without a Google account.
 *
 * Everything here is hostile input: a header value is written by whoever sent
 * the message, so the cases below are the ones that actually occur — a spoofed
 * display name, a `Date:` in the future, a body nested three levels down, a
 * `payload` that is not there at all.
 */
class EmailGmailMappingTest extends TestCase
{
    private GmailMessageMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new GmailMessageMapper(new BodySanitiser);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @param array<int, array{name: string, value: string}> $headers */
    private function payload(array $headers, array $extra = []): array
    {
        return [
            'id'       => 'm-1',
            'threadId' => 't-1',
            'snippet'  => 'Could you quote for two flues',
            'labelIds' => ['INBOX', 'UNREAD'],
            'internalDate' => '1786521600000',
            'payload'  => ['headers' => $headers, ...$extra],
        ];
    }

    public function test_a_from_header_is_split_into_a_name_and_an_address(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => 'Bob Smith <bob@example.com>'],
            ['name' => 'Subject', 'value' => 'Quote please'],
            ['name' => 'To', 'value' => 'info@a-1chimney.com, sales@a-1chimney.com'],
        ]));

        $this->assertSame('Bob Smith', $summary->fromName);
        $this->assertSame('bob@example.com', $summary->fromAddress);
        $this->assertSame('Quote please', $summary->subject);
        $this->assertSame(['info@a-1chimney.com', 'sales@a-1chimney.com'], $summary->to);
        $this->assertTrue($summary->unreadAtProvider);
        $this->assertNull($summary->fromAuthenticated);
    }

    public function test_dmarc_pass_is_published_as_authenticated(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => 'Apple Business <hello@email.apple.com>'],
            ['name' => 'Authentication-Results', 'value' => 'mx.google.com; dkim=pass header.i=@apple.com; dmarc=pass header.from=apple.com'],
        ]));

        $this->assertTrue($summary->fromAuthenticated);
        $this->assertSame('hello@email.apple.com', $summary->fromAddress);
    }

    public function test_dmarc_fail_is_not_authenticated(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => 'Apple Business <hello@email.apple.com>'],
            ['name' => 'Authentication-Results', 'value' => 'mx.google.com; dmarc=fail'],
        ]));

        $this->assertFalse($summary->fromAuthenticated);
    }

    /**
     * The display name is not to be trusted, and is kept anyway.
     *
     * `"info@yourbank.com" <thief@example.net>` is the oldest trick in mail. The
     * answer is not to drop the name — every legitimate message has one — it is
     * that BOTH are published as separate fields, so a client can draw the
     * address beside the name rather than trusting the name to be one.
     */
    public function test_a_display_name_that_looks_like_an_address_stays_a_display_name(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => '"info@yourbank.com" <thief@example.net>'],
        ]));

        $this->assertSame('info@yourbank.com', $summary->fromName);
        $this->assertSame('thief@example.net', $summary->fromAddress);
    }

    public function test_a_bare_address_with_no_display_name_still_maps(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => 'noreply@example.com'],
        ]));

        $this->assertSame('', $summary->fromName);
        $this->assertSame('noreply@example.com', $summary->fromAddress);
    }

    /**
     * `internalDate` beats `Date:`, and that is a security choice.
     *
     * `Date:` is written by the sender and can say anything, including a date in
     * 2099 — which sorts a spam message permanently to the top of an inbox.
     * `internalDate` is when Google received it and the sender cannot set it.
     */
    public function test_the_senders_own_date_header_does_not_decide_when_it_arrived(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'From', 'value' => 'spam@example.net'],
            ['name' => 'Date', 'value' => 'Fri, 1 Jan 2099 00:00:00 +0000'],
        ]));

        $this->assertSame(2026, $summary->sentAt->year);
    }

    /** A message with no internalDate falls back to the header, and a broken one to null. */
    public function test_a_malformed_date_is_a_message_with_no_date_rather_than_an_error(): void
    {
        $payload = $this->payload([['name' => 'Date', 'value' => 'not a date at all']]);

        unset($payload['internalDate']);

        $this->assertNull($this->mapper->summary($payload)->sentAt);
    }

    /**
     * A header name is case-insensitive, and a repeated one keeps the FIRST.
     *
     * Two `Subject` lines is a known spoofing trick — some clients show the
     * first and some the last, and choosing deliberately beats letting array
     * order decide.
     */
    public function test_headers_are_case_insensitive_and_a_repeat_keeps_the_first(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'SUBJECT', 'value' => 'The real subject'],
            ['name' => 'subject', 'value' => 'Your account has been suspended'],
        ]));

        $this->assertSame('The real subject', $summary->subject);
    }

    /**
     * A subject carrying control characters is flattened before it reaches a list.
     *
     * U+202E reverses everything after it on screen; a newline in a subject is
     * how a header is smuggled into anything that later writes one.
     */
    public function test_a_subject_is_stripped_of_control_and_direction_characters(): void
    {
        $summary = $this->mapper->summary($this->payload([
            ['name' => 'Subject', 'value' => "Invoice\r\nBcc: everyone@evil.example.net\u{202E}"],
        ]));

        $this->assertStringNotContainsString("\r", $summary->subject);
        $this->assertStringNotContainsString("\n", $summary->subject);
        $this->assertStringNotContainsString("\u{202E}", $summary->subject);
        $this->assertStringContainsString('Invoice', $summary->subject);
    }

    /**
     * The HTML part, found three levels down, and sanitised on the way out.
     *
     * A message with an attachment and both body formats is `multipart/mixed`
     * containing `multipart/alternative` containing the two text parts — a
     * one-level scan finds neither.
     */
    public function test_a_nested_html_part_is_found_and_sanitised(): void
    {
        $detail = $this->mapper->detail($this->payload(
            [['name' => 'From', 'value' => 'bob@example.com']],
            [
                'mimeType' => 'multipart/mixed',
                'parts'    => [[
                    'mimeType' => 'multipart/related',
                    'parts'    => [[
                        'mimeType' => 'multipart/alternative',
                        'parts'    => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => $this->base64Url('plain fallback')]],
                            ['mimeType' => 'text/html', 'body' => ['data' => $this->base64Url(
                                '<p>The real body</p><script>alert(1)</script>'
                            )]],
                        ],
                    ]],
                ], [
                    'mimeType' => 'application/pdf',
                    'filename' => 'invoice.pdf',
                    'body'     => ['size' => 20480, 'attachmentId' => 'att-1'],
                ]],
            ],
        ));

        $wire = json_encode($detail->toArray());

        // HTML is preferred over plain text, and it arrived as blocks.
        $this->assertStringContainsString('The real body', $wire);
        $this->assertStringNotContainsString('plain fallback', $wire);
        $this->assertStringNotContainsString('alert', $wire);
        $this->assertStringNotContainsString('<script', $wire);

        // Raw HTML is held for the sanitiser and is not on the published
        // payload — that is how a script in the part cannot reach a client
        // that serialises the mapper's own object.
        $this->assertStringContainsString('<p>The real body</p>', (string) $detail->sourceHtml);
        $this->assertNull($detail->html);
        $this->assertArrayNotHasKey('source_html', $detail->toArray());

        // The attachment is listed, and its handle is sealed so Gmail's
        // attachmentId is not on the wire. The token carries the message id
        // as well, so a file on an older thread part still fetches the
        // right Gmail message.
        $this->assertSame('invoice.pdf', $detail->attachments[0]['name']);
        $this->assertSame('application/pdf', $detail->attachments[0]['mime']);
        $this->assertSame(20480, $detail->attachments[0]['bytes']);

        $token = $detail->toArray()['attachments'][0]['token'];
        $this->assertIsString($token);
        $this->assertStringNotContainsString('att-1', $wire);
        $this->assertSame("m-1\natt-1", \Illuminate\Support\Facades\Crypt::decryptString($token));
        $this->assertTrue($detail->summary->hasAttachments);
    }

    /** Gmail encodes bodies base64url and without padding. */
    public function test_unpadded_base64url_decodes(): void
    {
        foreach (['a', 'ab', 'abc', 'abcd', 'hello there ~ ?'] as $text) {
            $detail = $this->mapper->detail($this->payload([], [
                'mimeType' => 'text/plain',
                'body'     => ['data' => $this->base64Url($text)],
            ]));

            $this->assertStringContainsString(
                $text,
                implode('', array_map(fn ($b) => $b->text(), $detail->blocks)),
            );
        }
    }

    /** Signature logos drawn by cid are not attachments (Eve 11-11). */
    public function test_inline_pictures_are_not_attachments(): void
    {
        $png = fn (string $name, array $headers) => [
            'mimeType' => 'image/png',
            'filename' => $name,
            'headers'  => $headers,
            'body'     => ['size' => 600, 'attachmentId' => 'att-'.$name],
        ];

        $detail = $this->mapper->detail($this->payload(
            [['name' => 'From', 'value' => 'nikki@example.com']],
            [
                'mimeType' => 'multipart/mixed',
                'parts'    => [
                    ['mimeType' => 'text/html', 'body' => ['data' => $this->base64Url('<p>Hi</p><img src="cid:image001.png@01DC">')]],
                    $png('image001.png', [['name' => 'Content-ID', 'value' => '<image001.png@01DC>'], ['name' => 'Content-Disposition', 'value' => 'inline; filename="image001.png"']]),
                    $png('image002.png', [['name' => 'Content-ID', 'value' => '<image002.png@01DC>']]),
                    $png('photo.png', [['name' => 'Content-ID', 'value' => '<p@x>'], ['name' => 'Content-Disposition', 'value' => 'attachment; filename="photo.png"']]),
                    $png('scan.png', [['name' => 'Content-Disposition', 'value' => 'attachment; filename="scan.png"']]),
                ],
            ],
        ));

        $this->assertSame(['photo.png', 'scan.png'], array_column($detail->attachments, 'name'));
    }

    /**
     * A message that is nothing but an attachment says so.
     *
     * A real case rather than a defensive one, and an empty panel would read as
     * a fault rather than as a message with no words in it.
     */
    public function test_a_message_with_no_text_part_says_so_rather_than_showing_nothing(): void
    {
        $detail = $this->mapper->detail($this->payload([], [
            'mimeType' => 'application/pdf',
            'filename' => 'scan.pdf',
            'body'     => ['size' => 100],
        ]));

        $this->assertCount(1, $detail->blocks);
        $this->assertSame(BlockKind::Notice, $detail->blocks[0]->kind);
        $this->assertStringContainsString('attachment', $detail->blocks[0]->text());
    }

    /**
     * A payload this mapper does not recognise must not take the inbox down.
     *
     * Every one of these is a message somebody can send. What cannot be read
     * becomes an empty string, and the message still lists.
     */
    public function test_a_malformed_payload_maps_to_empty_values_rather_than_throwing(): void
    {
        foreach ([
            'nothing at all'      => [],
            'no payload'          => ['id' => 'm-2'],
            'headers not a list'  => ['id' => 'm-3', 'payload' => ['headers' => 'oops']],
            'header not an array' => ['id' => 'm-4', 'payload' => ['headers' => ['oops']]],
            'parts holding a scalar' => ['id' => 'm-5', 'payload' => ['parts' => ['oops']]],
        ] as $name => $payload) {
            $summary = $this->mapper->summary($payload);

            $this->assertIsString($summary->subject, $name);
            $this->assertIsString($summary->fromAddress, $name);
            $this->assertSame([], $summary->to, $name);
        }
    }

    /**
     * Gmail's UNREAD flag is not EzBizi's seen state.
     *
     * They answer different questions: UNREAD is one flag on the mailbox as
     * Google sees it, changed by anybody who opens the message in Gmail;
     * `message_seen` is about which of four colleagues has looked. A message
     * read in Gmail by the account owner is still unseen by the team.
     */
    public function test_the_provider_flag_is_published_separately_from_seen_by(): void
    {
        $payload = $this->payload([['name' => 'From', 'value' => 'bob@example.com']]);

        $payload['labelIds'] = ['INBOX'];

        $row = $this->mapper->summary($payload)->toArray();

        $this->assertFalse($row['unread_at_provider']);
        $this->assertSame([], $row['seen_by']);
        $this->assertArrayHasKey('unread_at_provider', $row);
        $this->assertArrayHasKey('seen_by', $row);
    }

    /**
     * Five messages, one conversation — one list row, with the count.
     *
     * This is the Gmail inbox. Listing each message is how five WhatsApp
     * templates become five rows; grouping on `threadId` is how they become
     * one row that says 5.
     */
    public function test_a_thread_is_one_row_with_the_latest_message_and_a_count(): void
    {
        $thread = [
            'id' => 't-wa',
            'messages' => [
                $this->threadMessage('m-1', '1000', 'WhatsApp Business Team <notification@facebookmail.com>', 'first', ['INBOX']),
                $this->threadMessage('m-2', '2000', 'WhatsApp Business Team <notification@facebookmail.com>', 'second', ['INBOX']),
                $this->threadMessage('m-3', '3000', 'WhatsApp Business Team <notification@facebookmail.com>', 'third', ['INBOX']),
                $this->threadMessage('m-4', '4000', 'WhatsApp Business Team <notification@facebookmail.com>', 'fourth', ['INBOX']),
                $this->threadMessage('m-5', '5000', 'WhatsApp Business Team <notification@facebookmail.com>', 'fifth', ['INBOX', 'UNREAD']),
            ],
        ];

        $row = $this->mapper->threadSummary($thread);

        $this->assertSame('m-5', $row->id);
        $this->assertSame('t-wa', $row->threadId);
        $this->assertSame(5, $row->messageCount);
        $this->assertSame('WhatsApp Business Team', $row->fromName);
        $this->assertSame('fifth', $row->snippet);
        $this->assertTrue($row->unreadAtProvider);
        $this->assertSame(5, $row->toArray()['message_count']);
    }

    /**
     * @param  string[]  $labels
     * @return array<string, mixed>
     */
    private function threadMessage(string $id, string $internalDate, string $from, string $snippet, array $labels): array
    {
        return [
            'id' => $id,
            'threadId' => 't-wa',
            'internalDate' => $internalDate,
            'snippet' => $snippet,
            'labelIds' => $labels,
            'payload' => [
                'headers' => [
                    ['name' => 'From', 'value' => $from],
                    ['name' => 'Subject', 'value' => 'Your WhatsApp message template state has been changed'],
                ],
            ],
        ];
    }
}
