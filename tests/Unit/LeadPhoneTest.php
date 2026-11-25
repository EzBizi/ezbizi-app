<?php

namespace Tests\Unit;

use App\Models\Lead;
use PHPUnit\Framework\TestCase;

/** Every source stores a US number the way Google sends it (Jose, 2026-10-01). */
class LeadPhoneTest extends TestCase
{
    public function test_us_numbers_become_e164_and_anything_uncertain_is_kept(): void
    {
        $this->assertSame('+18605550101', Lead::e164('8605550101'));
        $this->assertSame('+18605550101', Lead::e164('(860) 555-0101'));
        $this->assertSame('+18605550101', Lead::e164('1-860-555-0101'));
        $this->assertSame('+16175550101', Lead::e164('+16175550101'));
        $this->assertSame('+447700900123', Lead::e164('+447700900123'), 'another country is not rewritten');
        $this->assertSame('+18604421234', Lead::e164('+8604421234'), 'a US number missing its 1 (health sweep 11-25)');
        $this->assertSame('+3541234567', Lead::e164('+3541234567'), 'a 10-digit foreign number that is not NANP stays');
        $this->assertSame('5551234', Lead::e164('5551234'), 'too short to be sure');
        $this->assertSame('0123456789', Lead::e164('0123456789'), 'no US area code starts with 0');
        $this->assertNull(Lead::e164('  '));
    }
}
