<?php

namespace Tests\Unit;

use App\Support\Notifications\LeadNotificationText as T;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Jose's spec, 2026-10-06, "Source Names for notification - automations",
 * pinned verbatim. Every expected string below is copied from it, so a test
 * here going red means the words drifted from what he asked for.
 */
class LeadNotificationTextTest extends TestCase
{
    /** @return array<string, array{string, ?string, string}> */
    public static function leadTitles(): array
    {
        return [
            'LS call' => ['local_services', 'PHONE_CALL', 'You got a new "Local Services Call" lead'],
            'LS text' => ['local_services', 'MESSAGE', 'You got a new "Local Services Text" lead'],
            'LS booking' => ['local_services', 'BOOKING', 'You got a new "Local Services Online Booking" lead'],
            'Thumbtack' => ['thumbtack', null, 'You got a new "Thumbtack" text lead'],
            'Live Chat' => ['website_chat', null, 'You got a new "Live Chat" request'],
            'Website Form' => ['website_form', null, 'You got a new "Website Form" lead'],
            'Meta text' => ['facebook', 'MESSAGE', 'You got a new "Meta Text" lead'],
            'Meta form' => ['instagram', 'FORM', 'You got a new "Meta Form" lead'],
            'Workiz online' => ['workiz', 'ONLINE', 'You got a new "Workiz Online" lead'],
            'Workiz text' => ['workiz', 'TEXT', 'You got a new "Workiz Text" lead'],
        ];
    }

    #[DataProvider('leadTitles')]
    public function test_each_source_reads_as_jose_wrote_it(string $source, ?string $type, string $expected): void
    {
        $this->assertSame($expected, T::headline($source, $type));
    }

    public function test_the_three_whatsapp_sentences(): void
    {
        $this->assertSame('You got a new "WhatsApp" message (Unknown)', T::whatsappHeadline(T::WHATSAPP_UNKNOWN));
        $this->assertSame('You got a new "WhatsApp" lead', T::whatsappHeadline(T::WHATSAPP_LEAD));
        $this->assertSame('You got a new "WhatsApp" hiring request', T::whatsappHeadline(T::WHATSAPP_HIRING));
    }

    public function test_his_example_details_line(): void
    {
        $this->assertSame(
            '(319) 210-2005 - 1:10 min - A1 Chimney - Toms River',
            T::details('+13192102005', 70_000, 'A1 Chimney', 'Toms River'),
        );
    }

    public function test_a_missing_fact_drops_out_rather_than_leaving_a_dash(): void
    {
        // A text has no duration and this lead has no branch.
        $this->assertSame('(319) 210-2005 - A1 Chimney', T::details('3192102005', null, 'A1 Chimney', null));
    }

    public function test_a_reply_reads_as_jose_wrote_it(): void
    {
        $this->assertSame('You got a new reply on "Local Services Text"', T::replyHeadline('local_services', 'MESSAGE'));
    }

    public function test_a_local_services_call_has_no_reply_notification(): void
    {
        // Jose: "Get rid of a reply on LS Call notification". The text control
        // above proves this is the CALL being refused, not replies in general.
        $this->assertNull(T::replyHeadline('local_services', 'PHONE_CALL'));
    }

    public function test_a_reply_is_shown_up_to_55_characters_ellipsis_included(): void
    {
        $long = str_repeat('a', 80);
        $clipped = T::clip($long, T::REPLY_PREVIEW_MAX);

        $this->assertSame(55, mb_strlen($clipped));
        $this->assertStringEndsWith('…', $clipped);
        $this->assertSame('Can you come Friday?', T::clip('Can you come Friday?', 55), 'short replies untouched');
    }

    public function test_a_lead_type_not_yet_recorded_still_says_something_true(): void
    {
        // Meta and Workiz do not record lead_type yet; until they do, the
        // second word is missing — never a wrong one.
        $this->assertSame('You got a new "Meta" lead', T::headline('facebook', null));
        $this->assertSame('You got a new "Workiz" lead', T::headline('workiz', null));
    }
}
