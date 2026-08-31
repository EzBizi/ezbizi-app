<?php

namespace Tests\Feature\Phone;

use App\Support\Phone\Attestation;
use PHPUnit\Framework\TestCase;

/**
 * Reading a SHAKEN/STIR grade out of whichever field Twilio used.
 *
 * The distinction that carries the weight is null versus 'none'. Null is "we
 * were not looking"; 'none' is "the carrier really did send this unsigned".
 * Collapsing them would report every call from before capture as untrusted,
 * which is the more alarming reading and the wrong one.
 */
class AttestationTest extends TestCase
{
    public function test_it_reads_both_of_twilios_spellings(): void
    {
        // Inbound.
        $this->assertSame('A', Attestation::fromWebhook('TN-Validation-Passed-A', null));
        $this->assertSame('C', Attestation::fromWebhook('TN-Validation-Failed-C', null));
        $this->assertSame(Attestation::NONE, Attestation::fromWebhook('No-TN-Validation', null));

        // Outbound.
        $this->assertSame('A', Attestation::fromWebhook(null, 'A'));
        $this->assertSame('B', Attestation::fromWebhook(null, 'B'));
    }

    public function test_absent_is_not_the_same_as_unsigned(): void
    {
        $this->assertNull(
            Attestation::fromWebhook(null, null),
            'a call with no attestation FIELD was reported as an unsigned call',
        );

        $this->assertNull(Attestation::fromWebhook('', ''));

        $this->assertSame(
            Attestation::NONE,
            Attestation::fromWebhook('No-TN-Validation', null),
            'a call that really was unsigned was reported as never measured',
        );
    }

    public function test_an_unrecognised_value_is_unsigned_rather_than_a_grade(): void
    {
        // Twilio adding a value we do not know must not become a fake 'A'.
        $this->assertSame(Attestation::NONE, Attestation::fromWebhook(null, 'Z'));
        $this->assertSame(Attestation::NONE, Attestation::fromWebhook('something-else', null));
    }
}
