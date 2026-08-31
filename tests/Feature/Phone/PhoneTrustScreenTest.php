<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneTrustObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Trust & caller identity: the attestation summary and the observation log.
 */
class PhoneTrustScreenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection} */
    private function connected(): array
    {
        $t = $this->provisionTenant();

        $connection = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_COMPANY,
            'scope_id' => $t['company']->id,
        ]));

        return [$t, $connection];
    }

    private function outbound(array $t, PhoneConnection $connection, ?string $grade, string $sid): void
    {
        $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'twilio_sid' => 'CA'.str_pad($sid, 32, '0'),
            'direction' => PhoneCall::DIRECTION_OUTBOUND,
            'from_e164' => '+15615550300',
            'to_e164' => '+15615550111',
            'status' => 'completed',
            'attestation' => $grade,
        ]));
    }

    /**
     * The distinction the whole panel rests on.
     *
     * `none` is a call the carrier really did send unsigned — a bad answer,
     * and it belongs in the degraded bucket. `null` is a call from before
     * capture, which is not an answer at all. Folding them together would
     * report every historical call as untrusted, on a screen somebody opens
     * because they are already worried about exactly that.
     */
    public function test_uncaptured_calls_are_not_counted_as_untrusted(): void
    {
        [$t, $connection] = $this->connected();
        $viewer = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);

        $this->outbound($t, $connection, 'A', 'a1');
        $this->outbound($t, $connection, 'A', 'a2');
        $this->outbound($t, $connection, 'C', 'c1');
        $this->outbound($t, $connection, 'none', 'n1');

        // Three calls from before capture existed.
        foreach (['u1', 'u2', 'u3'] as $sid) {
            $this->outbound($t, $connection, null, $sid);
        }

        $data = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/trust/attestation?days=30')
            ->assertOk()
            ->json('data');

        $this->assertSame(7, $data['total']);
        $this->assertSame(4, $data['graded'], 'uncaptured calls were counted as graded');
        $this->assertSame(3, $data['uncaptured']);
        $this->assertSame(2, $data['full']);

        // C and none both count as degraded — 2 of the 4 GRADED calls.
        $this->assertSame(2, $data['degraded']);
        // assertEquals, not assertSame: JSON renders 50.0 as 50.
        $this->assertEquals(
            50.0,
            $data['degraded_rate'],
            'the rate was measured against uncaptured calls as well as graded ones',
        );
    }

    /**
     * "0% degraded" out of nothing reads as a clean bill of health, which is
     * the opposite of what an unmeasured account should be told.
     */
    public function test_a_screen_with_no_grades_reports_no_rate_rather_than_a_perfect_one(): void
    {
        [$t, $connection] = $this->connected();
        $viewer = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);

        $this->outbound($t, $connection, null, 'u1');

        $data = $this->signedInAs($viewer)
            ->getJson('/api/v1/phone/trust/attestation')
            ->assertOk()
            ->json('data');

        $this->assertNull($data['degraded_rate']);
        $this->assertFalse($data['capturing']);
        $this->assertSame(1, $data['uncaptured']);
    }

    public function test_an_observation_is_recorded_with_who_looked(): void
    {
        [$t, $connection] = $this->connected();
        $editor = $this->userWithRole($t, 'Phone', ['phone.view', 'phone.edit']);

        $this->signedInAs($editor)->postJson('/api/v1/phone/trust/observations', [
            'connection_id' => $connection->id,
            'e164' => '+15615550300',
            'carrier' => 'Verizon',
            'observed' => 'Scam Likely',
        ])->assertCreated();

        $rows = $this->signedInAs($editor)
            ->getJson('/api/v1/phone/trust/observations')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('Verizon', $rows[0]['carrier']);
        $this->assertSame('Scam Likely', $rows[0]['observed']);
        $this->assertSame($editor->name, $rows[0]['observed_by']);
    }

    public function test_recording_an_observation_needs_more_than_permission_to_look(): void
    {
        [$t, $connection] = $this->connected();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/phone/trust/observations', [
            'connection_id' => $connection->id,
            'e164' => '+15615550300',
            'carrier' => 'Verizon',
            'observed' => 'clean',
        ])->assertForbidden();

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0,
            PhoneTrustObservation::query()->count(),
        ));
    }
}
