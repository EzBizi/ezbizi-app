<?php

namespace Tests\Unit;

use App\Support\Email\GmailMessageMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Which Gmail messages count as bulk -- sent to many by a system.
 *
 * Jose, 2026-10-06: the new-email notification should fire only "for mail a
 * person actually wrote". Measured on a week of info@a-1chimney.com before
 * choosing these: a bulk header caught 173 of 591; Gmail's category label
 * caught 334 more (RingCentral, monday.com, Thumbtack, Indeed, Google), none
 * from a personal-mail domain.
 */
class GmailBulkMailTest extends TestCase
{
    /** @param list<array{name: string, value: string}> $headers */
    private function message(array $headers, array $labels = ['INBOX']): array
    {
        return [
            'id' => 'm-1', 'threadId' => 't-1', 'labelIds' => $labels,
            'payload' => ['headers' => [['name' => 'From', 'value' => 'a@b.test'], ...$headers]],
        ];
    }

    /** @return array<string, array{0: array, 1: list<string>, 2: bool}> */
    public static function cases(): array
    {
        $h = fn (string $n, string $v) => [['name' => $n, 'value' => $v]];

        return [
            'a person, no markers'                 => [[], ['INBOX'], false],
            'Primary category is not bulk'         => [[], ['INBOX', 'CATEGORY_PERSONAL'], false],
            'List-Unsubscribe'                     => [$h('List-Unsubscribe', '<mailto:u@x.test>'), ['INBOX'], true],
            'List-Unsubscribe, any case'           => [$h('list-unsubscribe', '<https://x.test/u>'), ['INBOX'], true],
            'an empty List-Unsubscribe is nothing' => [$h('List-Unsubscribe', '  '), ['INBOX'], false],
            'Precedence: bulk'                     => [$h('Precedence', 'bulk'), ['INBOX'], true],
            'Precedence: list'                     => [$h('Precedence', 'List'), ['INBOX'], true],
            'Precedence: first-class is a person'  => [$h('Precedence', 'first-class'), ['INBOX'], false],
            'Auto-Submitted: auto-replied'         => [$h('Auto-Submitted', 'auto-replied'), ['INBOX'], true],
            'Auto-Submitted: no is a person'       => [$h('Auto-Submitted', 'no'), ['INBOX'], false],
            'Updates category'                     => [[], ['INBOX', 'CATEGORY_UPDATES'], true],
            'Promotions category'                  => [[], ['INBOX', 'CATEGORY_PROMOTIONS'], true],
            'Social category'                      => [[], ['INBOX', 'CATEGORY_SOCIAL'], true],
            'Forums category'                      => [[], ['INBOX', 'CATEGORY_FORUMS'], true],
        ];
    }

    #[DataProvider('cases')]
    public function test_bulk_is_judged_from_headers_and_category(array $headers, array $labels, bool $bulk): void
    {
        $this->assertSame($bulk, app(GmailMessageMapper::class)->summary($this->message($headers, $labels))->isBulk);
    }

    /** The flag survives the copy the list makes when it adds seen-by names. */
    public function test_bulk_survives_adding_seen_by(): void
    {
        $summary = app(GmailMessageMapper::class)->summary(
            $this->message([['name' => 'List-Unsubscribe', 'value' => '<mailto:u@x.test>']]),
        );

        $this->assertTrue($summary->withSeenBy([])->isBulk);
    }

    /** Gmail escapes the snippet; the row shows it as text (Jose's screenshot: &lt; and &#39;). */
    public function test_the_snippet_is_entity_decoded_once(): void
    {
        $message = [...$this->message([]), 'snippet' => 'It&#39;s 2 &lt; 3 &amp;amp; done'];

        $this->assertSame("It's 2 < 3 &amp; done", app(GmailMessageMapper::class)->summary($message)->snippet);
    }
}
