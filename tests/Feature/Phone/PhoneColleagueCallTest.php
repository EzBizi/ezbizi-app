<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Calling a colleague rings the colleague.
 *
 * ── What broke ───────────────────────────────────────────────────
 *
 * Jose, 2026-09-24: *"calls between team members do not work"*.
 *
 * Measured on production first: six internal calls the night before, every
 * one dialled at a BARE identity — `019fd712…c51`, no device suffix — and
 * every one `completed` after three or four seconds having rung nothing.
 *
 * Devices have registered as `<user>_<device>` since 2026-09-10, when the
 * ring group dial was moved to name each device. The Dial pad's Call button on
 * a colleague still sends `client:<user>`, which is an address nothing listens
 * on. Nothing errored: Twilio dialled it, found nobody, and ended the call.
 */
class PhoneColleagueCallTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A colleague named by person reaches every device they have.
     */
    public function test_calling_a_colleague_rings_each_of_their_devices(): void
    {
        [$t, $number, $colleague] = $this->line(devices: ['desk', 'phone']);

        $xml = $this->callColleague($t, $number, $colleague);

        $this->assertStringContainsString(ClientIdentity::of($colleague, 'desk'), $xml);
        $this->assertStringContainsString(ClientIdentity::of($colleague, 'phone'), $xml);
    }

    /**
     * THE BARE IDENTITY IS NOT DIALLED when there is a device to dial.
     *
     * The case that says the defect is gone rather than merely joined. A build
     * that added the devices AND kept the bare noun would pass the test above
     * and still spend one of `<Dial>`'s ten nouns on an address nobody hears.
     */
    public function test_the_bare_identity_is_not_dialled_alongside_them(): void
    {
        [$t, $number, $colleague] = $this->line(devices: ['desk']);

        $xml = $this->callColleague($t, $number, $colleague);

        $this->assertStringNotContainsString(
            '>'.ClientIdentity::of($colleague).'<',
            $xml,
            'the bare identity — the address nothing listens on — was still dialled',
        );
    }

    /**
     * A colleague with no device signed in is still offered the bare identity.
     *
     * Behaviour kept rather than changed: it fails fast exactly as before. An
     * empty `<Dial>` would be invalid TwiML and fail with a Twilio error
     * instead of simply ending.
     */
    public function test_a_colleague_with_no_device_falls_back_to_the_person(): void
    {
        [$t, $number, $colleague] = $this->line(devices: []);

        $xml = $this->callColleague($t, $number, $colleague);

        $this->assertStringContainsString('>'.ClientIdentity::of($colleague).'<', $xml);
    }

    /**
     * An identity that already names a device is dialled as it is.
     *
     * The control. Without it the expansion could be swallowing every client
     * target and the tests above would not notice.
     */
    public function test_a_device_identity_is_dialled_unchanged(): void
    {
        [$t, $number, $colleague] = $this->line(devices: ['desk', 'phone']);

        $xml = $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound(
            $number->fresh(),
            [
                'outbound' => true,
                'dial_to' => 'client:'.ClientIdentity::of($colleague, 'desk'),
                'caller_id' => $number->e164,
            ],
        ));

        $this->assertStringContainsString(ClientIdentity::of($colleague, 'desk'), $xml);
        $this->assertStringNotContainsString(ClientIdentity::of($colleague, 'phone'), $xml);
    }

    private function callColleague(array $t, PhoneNumber $number, User $colleague): string
    {
        return $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound(
            $number->fresh(),
            [
                'outbound' => true,

                // Exactly what the Dial pad sends — `_clientTo()` in
                // call_tab.dart strips the hyphens from the person's id.
                'dial_to' => 'client:'.str_replace('-', '', (string) $colleague->id),
                'caller_id' => $number->e164,
            ],
        ));
    }

    /**
     * @param  list<string>  $devices
     * @return array{0: array<string,mixed>, 1: PhoneNumber, 2: User}
     */
    private function line(array $devices): array
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Bar Ohayon', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $colleague, $devices) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ($devices as $device) {
                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $colleague->id,
                    'device_id' => $device,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            return PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
            ]);
        });

        return [$t, $number, $colleague];
    }
}
