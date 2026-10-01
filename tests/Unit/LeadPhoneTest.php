<?php

namespace Tests\Unit;

use App\Models\Lead;
use PHPUnit\Framework\TestCase;

/** Every source stores a US number the way Google sends it (Jose, 2026-10-01). */
class LeadPhoneTest extends TestCase
{
    public function test_us_numbers_become_e164_and_anything_uncertain_is_kept(): void
    {
        $this->assertSame('+18606708648', Lead::e164('8606708648'));
        $this->assertSame('+18606708648', Lead::e164('(860) 670-8648'));
        $this->assertSame('+18606708648', Lead::e164('1-860-670-8648'));
        $this->assertSame('+16175043242', Lead::e164('+16175043242'));
        $this->assertSame('+447700900123', Lead::e164('+447700900123'), 'another country is not rewritten');
        $this->assertSame('5551234', Lead::e164('5551234'), 'too short to be sure');
        $this->assertSame('0123456789', Lead::e164('0123456789'), 'no US area code starts with 0');
        $this->assertNull(Lead::e164('  '));
    }
}
