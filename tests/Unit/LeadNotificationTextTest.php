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
            'LS call' => ['local_services', 'PHONE_CALL', 'New "Local Services Call" lead'],
            'LS text' => ['local_services', 'MESSAGE', 'New "Local Services Text" lead'],
            'LS booking' => ['local_services', 'BOOKING', 'New "Local Services Online Booking" lead'],
            'Thumbtack' => ['thumbtack', null, 'New "Thumbtack" text lead'],
            'Live Chat' => ['website_chat', null, 'New "Live Chat" request'],
            'Website Form' => ['website_form', null, 'New "Website Form" lead'],
            'Meta text' => ['facebook', 'MESSAGE', 'New "Meta Text" lead'],
            'Meta form' => ['instagram', 'FORM', 'New "Meta Form" lead'],
            'Workiz online' => ['workiz', 'ONLINE', 'New "Workiz Online" lead'],
            'Workiz text' => ['workiz', 'TEXT', 'New "Workiz Text" lead'],
        ];
    }

    #[DataProvider('leadTitles')]
    public function test_each_source_reads_as_jose_wrote_it(string $source, ?string $type, string $expected): void
    {
        $this->assertSame($expected, T::headline($source, $type));
    }

    public function test_the_three_whatsapp_sentences(): void
    {
        $this->assertSame('New "WhatsApp" message (Unknown)', T::whatsappHeadline(T::WHATSAPP_UNKNOWN));
        $this->assertSame('New "WhatsApp" lead', T::whatsappHeadline(T::WHATSAPP_LEAD));
        $this->assertSame('New "WhatsApp" hiring request', T::whatsappHeadline(T::WHATSAPP_HIRING));
    }

    public function test_his_example_details_line(): void
    {
        $this->assertSame(
            '(319) 555-0100 - 1:10 min - A1 Chimney - Toms River',
            T::details('+13195550100', 70_000, 'A1 Chimney', 'Toms River'),
        );
    }

    public function test_a_missing_fact_drops_out_rather_than_leaving_a_dash(): void
    {
        // A text has no duration and this lead has no branch.
        $this->assertSame('(319) 555-0100 - A1 Chimney', T::details('3195550100', null, 'A1 Chimney', null));
    }

    public function test_a_reply_reads_as_jose_wrote_it(): void
    {
        $this->assertSame('New reply on "Local Services Text"', T::replyHeadline('local_services', 'MESSAGE'));
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

    public function test_a_missed_call_reads_as_jose_chose(): void
    {
        $this->assertSame('Missed call - A-1 Chimney', T::missedCallHeadline('A-1 Chimney'));
        $this->assertSame('Missed call', T::missedCallHeadline(null), 'no company is never a guessed one');
        $this->assertSame('(319) 555-0100 - Chicago SEO line', T::missedCallDetails('+13195550100', 'Chicago SEO line'));
        $this->assertSame(
            '(319) 555-0100 - Chicago SEO line - left a voicemail',
            T::missedCallDetails('+13195550100', 'Chicago SEO line', voicemail: true),
        );
        $this->assertSame('(319) 555-0100', T::missedCallDetails('3195550100', null), 'an unnamed number drops out');
        // Production, 2026-10-07: a withheld caller arrived as from = null.
        $this->assertSame('Unknown caller - A1 Chimney - Voicemail', T::missedCallDetails(null, 'A1 Chimney - Voicemail'));
    }

    public function test_a_lead_type_not_yet_recorded_still_says_something_true(): void
    {
        // Meta and Workiz do not record lead_type yet; until they do, the
        // second word is missing — never a wrong one.
        $this->assertSame('New "Meta" lead', T::headline('facebook', null));
        $this->assertSame('New "Workiz" lead', T::headline('workiz', null));
    }
}
