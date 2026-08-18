<?php

namespace Tests\Feature;

use App\Support\Email\BlockKind;
use App\Support\Email\BodySanitiser;
use App\Support\Email\MicrosoftMessageMapper;
use Tests\TestCase;

/**
 * Graph JSON into this application's shapes — the half of the Outlook adapter
 * that can be exercised without an Azure account.
 */
class EmailMicrosoftMappingTest extends TestCase
{
    private MicrosoftMessageMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new MicrosoftMessageMapper(new BodySanitiser);
    }

    /** @param array<string, mixed> $extra */
    private function payload(array $extra = []): array
    {
        return [
            'id'               => 'm-1',
            'conversationId'   => 't-1',
            'subject'          => 'Quote please',
            'bodyPreview'      => 'Could you quote for two flues',
            'hasAttachments'   => false,
            'isRead'           => false,
            'receivedDateTime' => '2026-08-18T14:00:00Z',
            'from'             => [
                'emailAddress' => ['name' => 'Bob Smith', 'address' => 'bob@example.com'],
            ],
            'toRecipients' => [
                ['emailAddress' => ['name' => 'Info', 'address' => 'info@a-1chimney.com']],
                ['emailAddress' => ['name' => 'Sales', 'address' => 'sales@a-1chimney.com']],
            ],
            ...$extra,
        ];
    }

    public function test_a_from_address_is_split_into_a_name_and_an_address(): void
    {
        $summary = $this->mapper->summary($this->payload());

        $this->assertSame('Bob Smith', $summary->fromName);
        $this->assertSame('bob@example.com', $summary->fromAddress);
        $this->assertSame('Quote please', $summary->subject);
        $this->assertSame(['info@a-1chimney.com', 'sales@a-1chimney.com'], $summary->to);
        $this->assertTrue($summary->unreadAtProvider);
        $this->assertSame('t-1', $summary->threadId);
    }

    public function test_a_display_name_that_looks_like_an_address_stays_a_display_name(): void
    {
        $summary = $this->mapper->summary($this->payload([
            'from' => [
                'emailAddress' => ['name' => 'info@yourbank.com', 'address' => 'thief@example.net'],
            ],
        ]));

        $this->assertSame('info@yourbank.com', $summary->fromName);
        $this->assertSame('thief@example.net', $summary->fromAddress);
    }

    public function test_a_bare_address_with_no_display_name_still_maps(): void
    {
        $summary = $this->mapper->summary($this->payload([
            'from' => [
                'emailAddress' => ['name' => '', 'address' => 'noreply@example.com'],
            ],
        ]));

        $this->assertSame('', $summary->fromName);
        $this->assertSame('noreply@example.com', $summary->fromAddress);
    }

    public function test_the_senders_own_date_does_not_override_when_graph_received_it(): void
    {
        $summary = $this->mapper->summary($this->payload([
            'receivedDateTime' => '2026-03-01T12:00:00Z',
            'sentDateTime'     => '2099-01-01T00:00:00Z',
        ]));

        $this->assertSame(2026, $summary->sentAt->year);
        $this->assertSame(3, $summary->sentAt->month);
    }

    public function test_a_malformed_date_is_a_message_with_no_date_rather_than_an_error(): void
    {
        $this->assertNull($this->mapper->summary($this->payload([
            'receivedDateTime' => 'not a date at all',
        ]))->sentAt);
    }

    public function test_html_body_is_preferred_and_becomes_blocks(): void
    {
        $detail = $this->mapper->detail($this->payload([
            'body' => [
                'contentType' => 'html',
                'content'     => '<p>Please quote two flues.</p>',
            ],
        ]));

        $this->assertNotEmpty($detail->blocks);
        $this->assertSame(BlockKind::Paragraph, $detail->blocks[0]->kind);
    }

    public function test_a_missing_payload_still_lists(): void
    {
        $summary = $this->mapper->summary([]);

        $this->assertSame('', $summary->id);
        $this->assertSame('', $summary->fromAddress);
        $this->assertSame('', $summary->subject);
    }
}
