<?php

namespace Tests\Feature\Workiz;

use App\Support\Workiz\StateCode;
use Tests\TestCase;

/**
 * Every case here is a value measured in A-1's real Workiz data.
 */
class StateCodeTest extends TestCase
{
    public function test_a_state_name_becomes_its_code(): void
    {
        $this->assertSame('OH', StateCode::from('Ohio'));
        $this->assertSame('NJ', StateCode::from('New Jersey'));
        $this->assertSame('MA', StateCode::from('Massachusetts'));
        $this->assertSame('DC', StateCode::from('District of Columbia'));
    }

    /** The data is MIXED — `OH` is in it beside `Ohio`. */
    public function test_a_value_that_is_already_a_code_passes_through(): void
    {
        $this->assertSame('OH', StateCode::from('OH'));
        $this->assertSame('NY', StateCode::from('ny'));
    }

    /** `Ontario` is on live rows, despite Country being US on every one. */
    public function test_a_canadian_province_is_recognised(): void
    {
        $this->assertSame('ON', StateCode::from('Ontario'));
    }

    /**
     * NULL, not a truncation. "Oh" for Ohio is wrong in a way that looks right,
     * and the full address survives on the customer regardless.
     */
    public function test_an_unrecognised_value_is_null_rather_than_cut_to_two(): void
    {
        $this->assertNull(StateCode::from('Nordrhein-Westfalen'));
        $this->assertNull(StateCode::from(''));
        $this->assertNull(StateCode::from(null));
    }
}
