<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Support\Phone\ClientIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A call to a colleague names the colleague.
 *
 * ── The report ───────────────────────────────────────────────────
 *
 * Recent calls listed rows reading `01a020d0417c714ca5bf7fb3304070e1`, and
 * Jose asked what the strings were — the right question to ask of a call log
 * that names nobody.
 *
 * They are Voice identities. A call to a teammate is addressed to one rather
 * than to a number, and the webhook stores it in `to_e164` after stripping
 * the `client:` prefix. Correct data, unreadable.
 */
class PhoneCallPeerNameTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: \App\Models\User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Reader', ['phone.view']);

        $number = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550122',
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return $number;
        });

        return [$t, $number, $desk];
    }

    private function callTo(array $t, PhoneNumber $number, string $to): void
    {
        $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
            'direction' => PhoneCall::DIRECTION_OUTBOUND,
            'from_e164' => $number->e164,
            'to_e164' => $to,
            'status' => PhoneCall::STATUS_COMPLETED,
        ]));
    }

    /** @return array<string, mixed> the one row the history returns */
    private function firstRow(array $t, $desk): array
    {
        return $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls')
            ->assertOk()
            ->json('data')[0];
    }

    /**
     * A call to a customer carries the customer's name beside their id, so
     * the history can say who (UI/UX's Dial redesign, 2026-09-30).
     */
    public function test_a_call_to_a_customer_carries_their_name(): void
    {
        [$t, $number, $desk] = $this->line();

        $this->inTenant($t['org'], fn () => \App\Models\Customer::factory()->create([
            'owning_franchise_id' => $t['franchise']->id,
            'name' => 'Marta Delgado',
            'phone' => '+13055550142',
        ]));

        $this->callTo($t, $number, '+13055550142');

        $this->assertSame('Marta Delgado', $this->firstRow($t, $desk)['customer_name']);
    }

    /**
     * Jose, 2026-10-09: most Recent calls and every voicemail were a bare
     * number. A lead with the number, a customer whose phone is typed another
     * way, and a colleague all name it; a customer outranks a lead.
     */
    public function test_a_number_is_named_by_a_lead_a_typed_customer_or_a_colleague(): void
    {
        [$t, $number, $desk] = $this->line();

        $this->inTenant($t['org'], function () use ($t, $desk) {
            \App\Models\Lead::create([
                'organization_id' => $t['org']->getKey(),
                'company_id' => $t['company']->getKey(),
                'source' => \App\Models\Lead::SOURCE_WEBSITE_CHAT,
                'remote_id' => 'chat-names',
                'contact_name' => 'Lena Lead',
                'phone' => '561-555-0102',
                'phone_digits' => '5615550102',
                'status' => \App\Models\Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
            \App\Models\Customer::factory()->create([
                'owning_franchise_id' => $t['franchise']->id,
                'name' => 'Typed Customer',
                'phone' => '(561) 555-0101',
            ]);
            \App\Models\Lead::create([
                'organization_id' => $t['org']->getKey(),
                'company_id' => $t['company']->getKey(),
                'source' => \App\Models\Lead::SOURCE_WEBSITE_CHAT,
                'remote_id' => 'chat-loses',
                'contact_name' => 'Should Not Win',
                'phone_digits' => '5615550101',
                'status' => \App\Models\Lead::STATUS_NEW,
                'received_at' => now(),
            ]);
            $desk->update(['name' => 'Chaim Perez', 'phone' => '(308) 555-0100']);
        });

        foreach (['+15615550102', '+15615550101', '+13085550100', '+15550009999'] as $to) {
            $this->callTo($t, $number, $to);
        }

        $rows = collect($this->signedInAs($desk)->getJson('/api/v1/phone/calls')->assertOk()->json('data'))
            ->keyBy('to_e164');

        $this->assertSame(['Lena Lead', 'lead'], [$rows['+15615550102']['caller_name'], $rows['+15615550102']['caller_kind']]);
        $this->assertSame(['Typed Customer', 'customer'], [$rows['+15615550101']['caller_name'], $rows['+15615550101']['caller_kind']]);
        $this->assertSame(['Chaim Perez', 'colleague'], [$rows['+13085550100']['caller_name'], $rows['+13085550100']['caller_kind']]);
        $this->assertNull($rows['+15550009999']['caller_name']);
    }

    /** The control: a number nobody holds names nobody. */
    public function test_a_call_to_a_stranger_names_nobody(): void
    {
        [$t, $number, $desk] = $this->line();

        $this->callTo($t, $number, '+13055550199');

        $row = $this->firstRow($t, $desk);
        $this->assertNull($row['customer_id']);
        $this->assertNull($row['customer_name']);
    }

    public function test_a_call_to_a_colleague_carries_their_name(): void
    {
        [$t, $number, $desk] = $this->line();
        $ana = $this->userWithRole($t, 'Analorena Ventoso', ['phone.view']);

        $this->callTo($t, $number, ClientIdentity::of($ana));

        $this->assertSame(
            'Analorena Ventoso',
            $this->firstRow($t, $desk)['peer_name'],
            'the history shows a 32-character identity where a name belongs',
        );
    }

    /**
     * And it still names them once identities carry a device.
     *
     * `from_e164` / `to_e164` hold 32 characters — exactly a user id without
     * its hyphens — so the webhook stores the PERSON half of
     * `client:<person>_<device>` and the device half never reaches the column.
     * That was luck until it was written down; `clip()` says it outright now.
     *
     * This asserts the pair together: what the webhook would store for a
     * device identity, and that the history still names the person from it. An
     * exact-match lookup would stop finding anybody the moment somebody
     * registered a second device — silently, and only for people with two.
     */
    public function test_an_identity_with_a_device_still_names_the_person(): void
    {
        [$t, $number, $desk] = $this->line();
        $ana = $this->userWithRole($t, 'Analorena Ventoso', ['phone.view']);

        $full = ClientIdentity::of($ana, 'aaaa1111');
        $this->assertStringContainsString('_', $full, 'the identity names no device');

        $stored = ClientIdentity::userPartOf($full);
        $this->assertSame(32, strlen($stored), 'this would not fit the column');

        $this->callTo($t, $number, $stored);

        $this->assertSame('Analorena Ventoso', $this->firstRow($t, $desk)['peer_name']);
    }

    /**
     * An ordinary call names nobody, and that is right.
     *
     * The control. Without it a resolver that returned some name for every row
     * would pass the tests above, and every customer would be labelled with
     * whoever happened to sort first.
     */
    public function test_a_call_to_a_phone_number_has_no_peer_name(): void
    {
        [$t, $number, $desk] = $this->line();

        $this->callTo($t, $number, '+13055550100');

        $row = $this->firstRow($t, $desk);

        $this->assertNull($row['peer_name']);
        $this->assertSame('+13055550100', $row['to_e164']);
    }

    /** An identity nobody owns is left as it is rather than guessed at. */
    public function test_an_unknown_identity_is_not_invented(): void
    {
        [$t, $number, $desk] = $this->line();

        $this->callTo($t, $number, str_repeat('f', 32));

        $this->assertNull($this->firstRow($t, $desk)['peer_name']);
    }
}
