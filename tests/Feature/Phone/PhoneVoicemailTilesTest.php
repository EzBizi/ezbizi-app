<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneVoicemailBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Voicemail screen's tiles: To deal with, Dealt with, No transcript, All
 * (Mia, 2026-10-02, layout B).
 *
 * The screen asks for 50 messages and is told no total, so a count taken on
 * the client would stop at 50 and call it everything. These are counted on
 * the server over every message the filter matches, before the page is cut
 * and before the tile's own filter, so picking one does not zero the rest.
 */
class PhoneVoicemailTilesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: \App\Models\User, 3: PhoneVoicemailBox} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Voicemail desk', ['phone.view']);

        [$number, $box] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $box = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Front desk',
                'owner_type' => PhoneVoicemailBox::OWNER_COMPANY,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13057249601',
                'voicemail_box_id' => $box->id,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$number, $box];
        });

        return [$t, $number, $desk, $box];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function message(array $t, PhoneNumber $number, array $fields): PhoneCall
    {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => PhoneCall::STATUS_NO_ANSWER,
            'voicemail' => true,
            ...$fields,
        ]));
    }

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: \App\Models\User, 3: PhoneVoicemailBox} */
    private function mailbox(): array
    {
        [$t, $number, $desk, $box] = $this->line();

        // Two waiting (one never written down), one dealt with but blank, and
        // one whitespace-only transcript — which the screen also shows as "No
        // transcript", so the tile must count it too.
        $this->message($t, $number, ['voicemail_transcript' => 'Call me back.']);
        $this->message($t, $number, ['voicemail_transcript' => null]);
        $this->message($t, $number, ['voicemail_transcript' => null, 'dealt_at' => now()]);
        $this->message($t, $number, ['voicemail_transcript' => '  ', 'dealt_at' => now()]);

        // Not a voicemail: never counted.
        $this->message($t, $number, ['voicemail' => false]);

        return [$t, $number, $desk, $box];
    }

    public function test_the_tiles_count_everything_not_just_the_page(): void
    {
        [, , $desk, $box] = $this->mailbox();

        $expected = ['todo' => 2, 'dealt' => 2, 'no_transcript' => 3, 'all' => 4];

        // per_page=1 is the cap the screen hits at 50, made small: the rows
        // stop at one and the counts do not.
        $response = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?voicemail_only=1&per_page=1')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($expected, $response->json('meta.tiles'));

        // The same over one box.
        $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?box_id='.$box->id.'&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.tiles', $expected);
    }

    public function test_the_tile_filters_narrow_the_rows_and_leave_the_counts(): void
    {
        [, , $desk] = $this->mailbox();

        $todo = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?voicemail_only=1&dealt=0')
            ->assertOk();
        $this->assertCount(2, $todo->json('data'));
        $this->assertTrue(collect($todo->json('data'))->every(fn ($r) => $r['dealt_at'] === null));
        $this->assertSame(4, $todo->json('meta.tiles.all'), 'the tile filter changed the counts');

        $dealt = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?voicemail_only=1&dealt=1')
            ->assertOk();
        $this->assertCount(2, $dealt->json('data'));
        $this->assertTrue(collect($dealt->json('data'))->every(fn ($r) => $r['dealt_at'] !== null));

        $blank = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?voicemail_only=1&transcript=0')
            ->assertOk();
        $this->assertCount(3, $blank->json('data'));
        $this->assertNotContains('Call me back.', collect($blank->json('data'))->pluck('voicemail_transcript'));
    }

    /** The plain call list (Records, the desk) is not given tiles it never asked for. */
    public function test_a_list_that_is_not_voicemail_has_no_tiles(): void
    {
        [, , $desk] = $this->mailbox();

        $response = $this->signedInAs($desk)->getJson('/api/v1/phone/calls')->assertOk();

        $this->assertNull($response->json('meta'));
        $this->assertCount(5, $response->json('data'));
    }

    /** The search bar: `q` narrows by number, transcript or customer name; a blank `q` is no filter. */
    public function test_q_narrows_the_call_list_and_a_blank_q_changes_nothing(): void
    {
        [$t, $number, $desk] = $this->mailbox();
        $this->message($t, $number, ['voicemail' => false, 'from_e164' => '+17865550199']);
        $this->inTenant($t['org'], fn () => \App\Models\Customer::query()->create([
            'owning_franchise_id' => $t['franchise']->id,
            'number' => 'C-SEARCH',
            'name' => 'Odalys Fernandez',
            'phone' => '+17865550199',
        ]));

        $as = $this->signedInAs($desk);
        $this->assertCount(1, $as->getJson('/api/v1/phone/calls?q=call%20me')->assertOk()->json('data'));
        $this->assertCount(1, $as->getJson('/api/v1/phone/calls?q=(786)%20555-0199')->assertOk()->json('data'));
        $this->assertCount(1, $as->getJson('/api/v1/phone/calls?q=odalys')->assertOk()->json('data'));
        $this->assertCount(6, $as->getJson('/api/v1/phone/calls?q=')->assertOk()->json('data'));
        $this->assertCount(6, $as->getJson('/api/v1/phone/calls')->assertOk()->json('data'));
        // The tiles still count the whole box; the search narrows the rows.
        $this->signedInAs($desk)->getJson('/api/v1/phone/calls?voicemail_only=1&q=call%20me')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.tiles.all', 4);
    }

    /** With no per_page the list is a page of 50, newest first, not every call ever made. */
    public function test_the_call_list_pages_at_fifty_by_default(): void
    {
        [$t, $number, $desk] = $this->line();
        for ($i = 0; $i < 52; $i++) {
            $this->message($t, $number, ['voicemail' => false]);
        }

        $this->assertCount(50, $this->signedInAs($desk)->getJson('/api/v1/phone/calls')->assertOk()->json('data'));
        $this->assertCount(2, $this->signedInAs($desk)->getJson('/api/v1/phone/calls?page=2')->assertOk()->json('data'));
        $this->assertCount(52, $this->signedInAs($desk)->getJson('/api/v1/phone/calls?per_page=200')->assertOk()->json('data'));
    }
}
