<?php

namespace Tests\Unit;

use App\Support\Leads\LeadDetails;
use PHPUnit\Framework\TestCase;

/**
 * What LeadDetails reads out of a conversation. The first three texts are real
 * customer messages from production (2026-09-28), names and numbers as sent.
 */
class LeadDetailsTest extends TestCase
{
    public function test_the_real_messages(): void
    {
        $d = LeadDetails::from([
            'Clean chimmy and check cost?',
            '215 Lakeview Dr Michigan City Indiana.....in weather stone Village. Friday afternoons or Saturday mornings',
            'Dave Malkowski 2196692720',
            'mdave309@gmail.com.......wood burn',
        ]);

        $this->assertSame('215 Lakeview Dr Michigan City Indiana', $d['address']);
        $this->assertSame('Dave Malkowski', $d['name']);
        $this->assertNull($d['zip'], 'a phone number is not a ZIP');

        $this->assertSame('12 Hillcrest court in Ridgefield CT', LeadDetails::address('12 Hillcrest court in Ridgefield CT'), 'the town comes with it');
        $this->assertSame('5330 Barton Road North Ridgville', LeadDetails::from(['5330 Barton Road North Ridgville. I am off the next three days'])['address']);
        $this->assertSame('Jacob Garcia', LeadDetails::name('Hello my name is Jacob Garcia. I just had a technician come out'));
    }

    public function test_zip_codes(): void
    {
        $this->assertSame('46360', LeadDetails::zip('215 Lakeview Dr, Michigan City, IN 46360'));
        $this->assertSame('06877', LeadDetails::zip('my zip is 06877'));
        $this->assertNull(LeadDetails::zip('12345 Main Street'), 'a house number is not a ZIP');
        $this->assertNull(LeadDetails::zip('call me at 203-570-5822'));
    }

    public function test_the_newest_mention_wins(): void
    {
        $d = LeadDetails::from(['10 Oak St Danbury CT 06810', 'sorry wrong one, 44 Elm Street Bethel CT 06801']);

        $this->assertSame('44 Elm Street Bethel CT 06801', $d['address']);
        $this->assertSame('06801', $d['zip']);
    }

    public function test_nothing_is_invented(): void
    {
        $this->assertSame(['name' => null, 'address' => null, 'zip' => null], LeadDetails::from([
            'How much for a cleaning?', 'Interested in a quote', 'I have 2 fireplaces',
        ]));
    }
    /** Charlotte Owens, 2026-11-13: "Flowery Branch / I am available tomorrow" read as one address; the widget's labels were ignored. */
    public function test_availability_is_not_the_address_and_labels_win(): void
    {
        $sms = "5234 Bowman Springs Trail \nFlowery Branch\nI am available tomorrow any day next week.";
        $this->assertSame('5234 Bowman Springs Trail Flowery Branch', LeadDetails::from([$sms])['address']);
        $this->assertSame(['name' => 'Charlotte Owens', 'address' => '5234 Bowman Springs Trail Flowery Branch', 'zip' => '30542'], LeadDetails::from([
            $sms, "Requested: Monday\nName: Charlotte Owens\nPhone: +17705034521\nAddress: 5234 Bowman Springs Trail Flowery Branch\nZIP: 30542",
        ]));
    }
}
