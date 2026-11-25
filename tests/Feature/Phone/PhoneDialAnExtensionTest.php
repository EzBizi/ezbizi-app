<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Models\PhoneExtension;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A caller can type the extension they want.
 *
 * ── The gap this closes ──────────────────────────────────────────
 *
 * Jose's own IVR prompt has told callers for weeks that *"if you know your
 * parties extension you may dial it at any time"*, and nothing listened:
 * `dialExtension` was reachable only from a menu key BOUND to one fixed
 * extension. Assigning somebody 0101 was a setting nobody could use.
 *
 * ── Why zero is the key ─────────────────────────────────────────
 *
 * Every extension begins with one — Jose's rule of 2026-09-22 — and that is
 * what makes this possible rather than merely tidy. A menu offers 1 to 9, so
 * an extension starting with one of those would be ambiguous against a menu
 * choice at the instant it was pressed.
 */
class PhoneDialAnExtensionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Pressing zero asks for the extension.
     */
    public function test_zero_asks_the_caller_to_type_an_extension(): void
    {
        [$t, $number] = $this->line();

        $xml = $this->render($t, $number, ['digits' => '0']);

        $this->assertStringContainsString('<Gather', $xml);
        $this->assertStringContainsString('Enter the extension', $xml);

        // AND IT SAYS WHERE TO SEND THEM. Twilio posts the collected digits to
        // the action URL, and without the flag on it the reply is read as a
        // menu key — `0101` is a valid press followed by three more.
        $this->assertStringContainsString('extension=1', $xml);
    }

    /**
     * And typing one rings the person who holds it.
     */
    public function test_the_typed_extension_rings_its_holder(): void
    {
        [$t, $number, $alice, $bruno] = $this->line();

        $xml = $this->render($t, $number, [
            'digits' => '0101',
            'extension_entry' => true,
        ]);

        $this->assertStringContainsString(ClientIdentity::of($alice, 'alice-desk'), $xml);
        $this->assertStringNotContainsString(ClientIdentity::of($bruno, 'bruno-desk'), $xml);
    }

    /**
     * WITHOUT THE FLAG the same digits are a menu key, not an extension.
     *
     * The case that says the flag is doing the work. `0101` arrives in exactly
     * the same `Digits` field either way, so a build that ignored the flag and
     * always resolved extensions would pass the test above and fail this one —
     * and would also break every menu, because `1` would stop being option
     * one.
     */
    public function test_the_same_digits_without_the_flag_are_not_an_extension(): void
    {
        [$t, $number, $alice] = $this->line();

        $xml = $this->render($t, $number, ['digits' => '0101']);

        $this->assertStringNotContainsString(ClientIdentity::of($alice, 'alice-desk'), $xml);
    }

    /**
     * An account with no extensions is not asked for one.
     *
     * Zero falls through exactly as it does today. Being told "enter the
     * extension" with nothing that works to type is worse than zero doing what
     * it does on every other line.
     */
    public function test_a_business_with_no_extensions_is_not_asked_for_one(): void
    {
        [$t, $number] = $this->line(withExtensions: false);

        $xml = $this->render($t, $number, ['digits' => '0']);

        $this->assertStringNotContainsString('Enter the extension', $xml);
    }

    /**
     * A menu that uses zero for something keeps it.
     *
     * Somebody's existing "press 0 for the operator" must not be taken over by
     * a feature added later. The menu's own option wins.
     */
    public function test_a_menu_that_claims_zero_keeps_it(): void
    {
        [$t, $number] = $this->line(zeroIsAMenuKey: true);

        $xml = $this->render($t, $number, ['digits' => '0']);

        $this->assertStringNotContainsString('Enter the extension', $xml);
        $this->assertStringContainsString('<Record', $xml);
    }

    /** @param array<string, mixed> $context */
    private function render(array $t, PhoneNumber $number, array $context): string
    {
        return $this->inTenant($t['org'], fn () => (new TwimlRenderer)->inbound(
            $number->fresh(),
            [...$context, 'extension_action' => 'https://example.test/voice?batch=0&extension=1'],
        ));
    }

    /** @return array{0: array<string,mixed>, 1: PhoneNumber, 2: User, 3: User} */
    private function line(bool $withExtensions = true, bool $zeroIsAMenuKey = false): array
    {
        $t = $this->provisionTenant();
        $alice = $this->userWithRole($t, 'Alice Nolan', ['phone.view']);
        $bruno = $this->userWithRole($t, 'Bruno Vega', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $alice, $bruno, $withExtensions, $zeroIsAMenuKey) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            foreach ([[$alice, 'alice-desk'], [$bruno, 'bruno-desk']] as [$person, $device]) {
                PhonePresence::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'state' => PhonePresence::STATE_REGISTERED,
                ]);

                PhoneDeviceRegistration::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $person->id,
                    'device_id' => $device,
                    'platform' => 'web',
                    'last_seen_at' => now(),
                ]);
            }

            $group = PhoneRingGroup::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'mode' => 'simultaneous',
                'timeout_seconds' => 30,
                'member_user_ids' => [$bruno->id],
                'member_role_ids' => [],
                'overflow' => 'voicemail',
            ]);

            $options = [['digit' => '1', 'action' => 'ring_group']];
            if ($zeroIsAMenuKey) {
                $options[] = ['digit' => '0', 'action' => 'voicemail'];
            }

            $flow = PhoneFlow::query()->create([
                'connection_id' => $connection->id,
                'name' => 'Main menu',
                'nodes' => [
                    'type' => 'menu',
                    'prompt' => 'Press 1 for the desk, or dial an extension.',
                    'options' => $options,
                ],
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550113',
                'ring_group_id' => $group->id,
                'flow_id' => $flow->id,
            ]);

            if ($withExtensions) {
                PhoneExtension::query()->create([
                    'organization_id' => $t['org']->id,
                    'connection_id' => $connection->id,
                    'user_id' => $alice->id,
                    'extension' => '0101',
                ]);
            }

            return $number;
        });

        return [$t, $number, $alice, $bruno];
    }
}
