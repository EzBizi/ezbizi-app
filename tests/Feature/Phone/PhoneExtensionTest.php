<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneFlow;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Extensions: a short number that reaches one person.
 *
 * Twilio has no concept of one. To Twilio these are Client identities, and
 * resolving the digits before dialling is the whole feature.
 */
class PhoneExtensionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function member(array $t, string $name, string $state): User
    {
        $role = Role::query()->firstOrCreate(
            ['organization_id' => $t['org']->id, 'name' => 'Desk'],
            ['is_system' => false],
        );

        $user = User::query()->create([
            'organization_id' => $t['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower($name).'@'.$t['org']->slug.'.test',
            'password' => 'a-real-password',
            'is_active' => true,
        ]);

        PhonePresence::query()->create([
            'connection_id' => $t['connection']->id,
            'user_id' => $user->id,
            'state' => $state,
        ]);

        return $user;
    }

    /** @return array<string, mixed> */
    private function ready(): array
    {
        $t = $this->provisionTenant();

        $t['connection'] = $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
        ]));

        return $t;
    }

    /** @param array<string, mixed> $options */
    private function lineWithMenu(array $t, array $options, string $sid): PhoneNumber
    {
        $flow = PhoneFlow::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $t['connection']->id,
            'name' => 'Main',
            'nodes' => [
                'type' => 'menu',
                'prompt' => 'Dial an extension.',
                'options' => $options,
            ],
        ]);

        return PhoneNumber::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $t['connection']->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'twilio_sid' => 'PN'.str_pad($sid, 32, 'e'),
            'e164' => '+1561555'.substr($sid, 0, 4),
            'label' => 'Main',
            'after_hours' => 'voicemail',
            'flow_id' => $flow->id,
        ]);
    }

    /**
     * The assertion that matters is the TwiML: a caller pressing the key
     * reaches that person's handset, not a generic ring group.
     */
    public function test_a_menu_key_dials_the_person_holding_the_extension(): void
    {
        $t = $this->ready();

        [$xml, $identity] = $this->inTenant($t['org'], function () use ($t) {
            $bar = $this->member($t, 'Bar', PhonePresence::STATE_REGISTERED);

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $t['connection']->id,
                'user_id' => $bar->id,
                'extension' => '102',
            ]);

            $number = $this->lineWithMenu($t, [
                ['digit' => '2', 'action' => 'extension', 'extension' => '102'],
            ], '9300');

            return [
                (new TwimlRenderer)->inbound($number->fresh(), ['digits' => '2']),
                ClientIdentity::of($bar),
            ];
        });

        $this->assertStringContainsString('<Dial', $xml);
        $this->assertStringContainsString($identity, $xml);
        $this->assertStringNotContainsString('<Record', $xml);

        // And the person who picks up is written down. An extension dial names
        // one person but still rings each of their devices, so the leg that
        // answers is no more knowable here than in a ring group — the same
        // <Client url> is what reports it.
        $this->assertStringContainsString('/phone/answered/', $xml);
    }

    /**
     * Three failures, and a caller deserves to be told which.
     *
     * "Nobody holds these digits" and "somebody does but is away" sound
     * identical if both answer with silence, and a caller who hears nothing
     * concludes the business is shut.
     */
    public function test_an_unknown_extension_and_an_away_person_say_different_things(): void
    {
        $t = $this->ready();

        [$unknown, $away] = $this->inTenant($t['org'], function () use ($t) {
            $jose = $this->member($t, 'Jose', PhonePresence::STATE_DND);

            PhoneExtension::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $t['connection']->id,
                'user_id' => $jose->id,
                'extension' => '103',
            ]);

            $number = $this->lineWithMenu($t, [
                ['digit' => '9', 'action' => 'extension', 'extension' => '999'],
                ['digit' => '3', 'action' => 'extension', 'extension' => '103'],
            ], '9301');

            $renderer = new TwimlRenderer;

            return [
                $renderer->inbound($number->fresh(), ['digits' => '9']),
                $renderer->inbound($number->fresh(), ['digits' => '3']),
            ];
        });

        $this->assertStringContainsString('no extension', $unknown);
        $this->assertStringContainsString('<Record', $unknown);

        // Named, so the caller knows they reached the right extension.
        $this->assertStringContainsString('Jose is not available', $away);
        $this->assertStringContainsString('<Record', $away);
        $this->assertStringNotContainsString('<Dial', $away);
    }

    public function test_two_people_cannot_hold_one_extension(): void
    {
        $t = $this->ready();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);

        [$bar, $ana] = $this->inTenant($t['org'], fn () => [
            $this->member($t, 'Bar', PhonePresence::STATE_REGISTERED),
            $this->member($t, 'Ana', PhonePresence::STATE_REGISTERED),
        ]);

        $this->signedInAs($editor)->putJson('/api/v1/phone/extensions', [
            'connection_id' => $t['connection']->id,
            'user_id' => $bar->id,
            'extension' => '102',
        ])->assertOk();

        // Refused by name, not by a 500 from the unique index.
        $this->signedInAs($editor)->putJson('/api/v1/phone/extensions', [
            'connection_id' => $t['connection']->id,
            'user_id' => $ana->id,
            'extension' => '102',
        ])->assertStatus(422)->assertJsonValidationErrors('extension');

        // Clearing is the same call with nothing in the field.
        $this->signedInAs($editor)->putJson('/api/v1/phone/extensions', [
            'connection_id' => $t['connection']->id,
            'user_id' => $bar->id,
            'extension' => null,
        ])->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertSame(
            0,
            PhoneExtension::query()->count(),
        ));
    }
}
