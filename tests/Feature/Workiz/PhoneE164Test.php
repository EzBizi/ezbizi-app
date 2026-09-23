<?php

namespace Tests\Feature\Workiz;

use App\Support\Workiz\PhoneE164;
use Tests\TestCase;

/**
 * Workiz's bare digits become E.164, and nothing else is rewritten.
 *
 * The defect this guards is not the one it looks like. `formatPhoneNumber()`
 * was already wired at both display sites and was returning the number
 * unchanged, correctly, because a bare ten is not E.164. The wrong value was in
 * the column: 617 of 632 production customers held bare digits, and the API,
 * the database and Twilio all key on E.164 — so those rows were the wrong
 * number to call, not merely an ugly one.
 */
class PhoneE164Test extends TestCase
{
    public function test_a_bare_ten_becomes_e164(): void
    {
        // The exact shape all 617 production rows hold, Jose's screenshot included.
        $this->assertSame('+14242095909', PhoneE164::from('4242095909'));
    }

    public function test_a_value_already_ours_is_untouched(): void
    {
        // The projection re-runs every five minutes. If this rewrote its own
        // output the column would churn on every sweep.
        $this->assertSame('+14242095909', PhoneE164::from('+14242095909'));
    }

    public function test_a_spelled_out_country_code_is_not_a_different_number(): void
    {
        $this->assertSame('+14242095909', PhoneE164::from('14242095909'));
    }

    /**
     * The arm that stops this becoming "anything goes".
     *
     * Relaxing a strict converter is exactly the change that quietly starts
     * stamping `+1` onto things that are not NANP numbers. An area code or
     * exchange starting 0 or 1 is forbidden, so these come back as they
     * arrived rather than being dressed up as American.
     */
    public function test_a_number_that_is_not_nanp_is_left_alone(): void
    {
        $this->assertSame('0123456789', PhoneE164::from('0123456789'));
        $this->assertSame('4241095909', PhoneE164::from('4241095909'));
        $this->assertSame('+442071838750', PhoneE164::from('+442071838750'));
    }

    /**
     * A phone that will not map is KEPT, which is the opposite of StateCode.
     *
     * Dropping an unmappable state is right because "Oh" is wrong in a way that
     * looks right. Dropping an unmappable phone would throw away the only way to
     * reach a customer, so it survives unformatted and honest.
     */
    public function test_something_unmappable_survives_rather_than_being_nulled(): void
    {
        $this->assertSame('call the shop', PhoneE164::from('call the shop'));
        $this->assertNull(PhoneE164::from(''));
        $this->assertNull(PhoneE164::from(null));
    }
}
