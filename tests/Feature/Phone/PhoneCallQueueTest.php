<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhonePresence;
use App\Models\PhoneRingGroup;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone\TwimlRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;
use Twilio\Security\RequestValidator;

/**
 * Callers wait in a line instead of being answered by a machine.
 *
 * ── What was asked for ───────────────────────────────────────────
 *
 * Jose, naming RingCentral: "if everyone is on calls, it leaves callers
 * waiting on a queue, so they don't go straight to voicemail" — and the
 * announcement, "you are 3rd in the queue".
 *
 * ── The shape of the thing being tested ──────────────────────────
 *
 * Twilio re-requests the `waitUrl` every time the document it returns runs out
 * of verbs, so the hold loop is a clock somebody else winds. Every pass asks
 * whether anybody has become free and answers `<Leave>` when one has; leaving
 * fires the `<Enqueue action>`, which is the ordinary voice webhook, and the
 * ring happens through the same path as every other call.
 *
 * That means there is no worker to test and no timer to fast-forward. The
 * cases below are the answers the loop can give, each driven by the parameters
 * Twilio actually sends.
 */
class PhoneCallQueueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const AUTH_TOKEN = 'call-queue-auth-token';

    /**
     * Nobody free: the caller is held rather than sent to voicemail.
     *
     * The control is the second assertion. A document that both enqueues AND
     * records would pass a test that only looked for `<Enqueue>`, and it is
     * exactly the failure this feature exists to prevent — the caller hearing
     * hold music with a voicemail beep underneath it.
     */
    public function test_a_queue_holds_the_caller_instead_of_taking_a_message(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->queueLine($t, available: false);

            return (new TwimlRenderer)->inbound($number->fresh(), ['skip_menu' => true]);
        });

        $this->assertStringContainsString('<Enqueue', $xml);
        $this->assertStringNotContainsString(
            '<Record',
            $xml,
            'the caller was queued AND sent to voicemail, so they hear a beep '
            .'under the hold music',
        );
    }

    /**
     * The queue is named after the GROUP, not after anything a person types.
     *
     * Twilio finds a queue by name and creates one the first time an
     * `<Enqueue>` uses a name it has not seen. So the name is the identity: a
     * name derived from the group's title would strand everybody already
     * standing in the line the moment somebody renamed the group.
     */
    public function test_the_queue_name_survives_a_rename(): void
    {
        $t = $this->provisionTenant();

        [$before, $after] = $this->inTenant($t['org'], function () use ($t) {
            [$number, $group] = $this->queueLine($t, available: false);

            $first = (new TwimlRenderer)->inbound($number->fresh(), ['skip_menu' => true]);
            $group->forceFill(['name' => 'Something else entirely'])->save();

            return [$first, (new TwimlRenderer)->inbound($number->fresh(), ['skip_menu' => true])];
        });

        $this->assertStringContainsString('ezbizi-', $before);
        $this->assertSame(
            $this->queueNameIn($before),
            $this->queueNameIn($after),
            'renaming the group moved the queue, which strands every caller '
            .'already standing in the old one',
        );
    }

    /** Somebody free and you are at the front: you stop waiting. */
    public function test_the_front_caller_leaves_when_somebody_becomes_free(): void
    {
        [$org, $number, $group, $connection] = $this->queueFixture(
            $this->provisionTenant(),
            available: true,
        );

        $this->postWait($org, $connection, $number, $group, [
            'QueuePosition' => 1,
            'QueueTime' => 10,
        ])->assertOk()->assertSee('<Leave', false);
    }

    /**
     * Somebody free but you are NOT at the front: you keep waiting.
     *
     * A free agent is one agent. Releasing the whole queue sends five callers
     * at one person, four of whom are told a moment later that nobody
     * answered — and they rejoin behind people who arrived after them.
     */
    public function test_only_the_front_caller_is_released(): void
    {
        [$org, $number, $group, $connection] = $this->queueFixture(
            $this->provisionTenant(),
            available: true,
        );

        $this->postWait($org, $connection, $number, $group, [
            'QueuePosition' => 3,
            'QueueTime' => 10,
        ])
            ->assertOk()
            ->assertDontSee('<Leave', false)
            ->assertSee('third in line', false);
    }

    /**
     * Nobody free: you are told where you are, and then you hear music.
     *
     * The position is the feature Jose named. The `<Play>` is what makes the
     * loop a loop — Twilio comes back when the document runs out of verbs, so
     * a document with nothing after the announcement re-announces continuously.
     */
    public function test_a_waiting_caller_is_told_their_position(): void
    {
        [$org, $number, $group, $connection] = $this->queueFixture(
            $this->provisionTenant(),
            available: false,
        );

        $this->postWait($org, $connection, $number, $group, [
            'QueuePosition' => 3,
            'QueueTime' => 20,
        ])
            ->assertOk()
            ->assertSee('third in line', false)
            ->assertSee('<Play>', false)
            ->assertDontSee('<Leave', false);
    }

    /**
     * Held for as long as the group allows: let them go.
     *
     * Leaving reaches the action URL with `queued=1`, and with nobody
     * available that ends at voicemail — where the caller would have gone
     * immediately if this feature did not exist. The queue buys them the wait;
     * it does not trap them in it.
     */
    public function test_a_caller_held_too_long_is_let_out(): void
    {
        $t = $this->provisionTenant();
        [$org, $number, $group, $connection] = $this->queueFixture($t, available: false);

        $this->inTenant($t['org'], fn () => PhoneRingGroup::query()
            ->whereKey($group)->update(['queue_max_seconds' => 60]));

        $this->postWait($org, $connection, $number, $group, [
            'QueuePosition' => 4,
            'QueueTime' => 61,
        ])->assertOk()->assertSee('<Leave', false);
    }

    /**
     * AND THE CIRCLE IS CLOSED: leaving the queue cannot rejoin it.
     *
     * This is the one that costs money if it is wrong. A caller released to a
     * ring that nobody answers reaches overflow again; without the `queued`
     * flag that overflow is the queue, so they rejoin at the back and hold for
     * ever — billed per loop, and indistinguishable from hold music working.
     */
    public function test_a_caller_who_has_left_the_queue_does_not_rejoin_it(): void
    {
        $t = $this->provisionTenant();

        $xml = $this->inTenant($t['org'], function () use ($t) {
            [$number] = $this->queueLine($t, available: false);

            return (new TwimlRenderer)->afterDial($number->fresh(), [
                'queued' => true,
                'dial_status' => 'no-answer',
            ]);
        });

        $this->assertStringNotContainsString(
            '<Enqueue',
            $xml,
            'a caller let out of the queue was put straight back into it',
        );
        $this->assertStringContainsString(
            '<Record',
            $xml,
            'having left the queue with nobody free, the caller should reach '
            .'voicemail rather than silence',
        );
    }

    /**
     * AND IT SURVIVES THE RING, which is where I first got it wrong.
     *
     * The test above proves the flag works on the hop that leaves the queue.
     * That hop rings the group, and the ring posts its own result to an
     * `action` URL that this app builds — so the flag only holds for a second
     * attempt if it is written into that URL.
     *
     * It was not. Twilio sends `QueueResult` once, on the way out, and every
     * hop after that looked like a first attempt: overflow, queue, hold,
     * release, ring, overflow. A loop, billed per pass, that sounds exactly
     * like hold music working.
     *
     * A guard only watches the branch that reaches it, and the branch I tested
     * was the one hop where the guard could not fail.
     */
    public function test_the_ring_after_the_queue_carries_the_flag_onwards(): void
    {
        $t = $this->provisionTenant();
        [$org, $number, , $connection] = $this->queueFixture($t, available: true);

        // THE REAL ENDPOINT, because the URL being asserted is one this app
        // builds. My first attempt at this test handed the renderer a
        // `dial_action` of its own and then asserted the renderer echoed it,
        // which passes whether or not the controller writes the flag — I
        // sabotaged the controller and the test stayed green.
        $xml = $this->postVoice($org, $connection, [
            'QueueResult' => 'bridged',
            'To' => $this->inTenant($t['org'], fn () => PhoneNumber::query()
                ->whereKey($number)->value('e164')),
        ])->assertOk()->getContent();

        $this->assertStringContainsString(
            '<Dial',
            $xml,
            'somebody was free, so leaving the queue should ring them',
        );
        $this->assertStringContainsString(
            'queued=1',
            $xml,
            'the ring that follows the queue posts back to a URL that has '
            .'forgotten the caller was ever queued, so the next overflow puts '
            .'them back in the line they just left — for ever, billed per pass',
        );
    }

    /**
     * A group switched away from a queue does not hold the people in it.
     *
     * The wait loop keeps running for callers already in the line after
     * somebody edits the group, because Twilio is driving it and knows nothing
     * about our settings. Holding them against a setting that no longer exists
     * is how a queue outlives its own deletion.
     */
    public function test_turning_the_queue_off_releases_the_people_in_it(): void
    {
        $t = $this->provisionTenant();
        [$org, $number, $group, $connection] = $this->queueFixture($t, available: false);

        $this->inTenant($t['org'], fn () => PhoneRingGroup::query()
            ->whereKey($group)->update(['overflow' => 'voicemail']));

        $this->postWait($org, $connection, $number, $group, [
            'QueuePosition' => 2,
            'QueueTime' => 10,
        ])->assertOk()->assertSee('<Leave', false);
    }

    /**
     * The estimate is off unless it is asked for, and it rounds up.
     *
     * A caller told two minutes who waits six hangs up angrier than one told
     * nothing, so it is opt-in; rounding up is the direction that cannot
     * produce that complaint.
     */
    public function test_the_estimated_wait_is_opt_in_and_rounds_up(): void
    {
        $t = $this->provisionTenant();
        [$org, $number, $group, $connection] = $this->queueFixture($t, available: false);

        $params = ['QueuePosition' => 2, 'QueueTime' => 20, 'AvgQueueTime' => 45];

        $this->postWait($org, $connection, $number, $group, $params)
            ->assertOk()
            ->assertDontSee('estimated wait', false);

        $this->inTenant($t['org'], fn () => PhoneRingGroup::query()
            ->whereKey($group)->update(['queue_announce_wait' => true]));

        // 45 seconds average, second in line: 90 seconds, said as two minutes.
        $this->postWait($org, $connection, $number, $group, $params)
            ->assertOk()
            ->assertSee('about 2 minutes', false);
    }

    // ── fixtures ────────────────────────────────────────────────────

    /** @return array{0: PhoneNumber, 1: PhoneRingGroup} */
    private function queueLine(array $tenant, bool $available): array
    {
        $connection = $this->connection($tenant);
        $user = $this->member($tenant, 'Desk');

        // A presence row either way. "Nobody free" has to mean somebody who IS
        // in the group and is not taking calls — a group with no presence rows
        // at all reaches the same branch for a different reason, and would
        // pass this test with the availability check deleted.
        PhonePresence::query()->create([
            'connection_id' => $connection->id,
            'user_id' => $user->id,
            'state' => $available
                ? PhonePresence::STATE_REGISTERED
                : PhonePresence::STATE_DND,
        ]);

        $group = PhoneRingGroup::query()->create([
            'connection_id' => $connection->id,
            'name' => 'Front desk',
            'mode' => 'simultaneous',
            'timeout_seconds' => 30,
            'member_user_ids' => [$user->id],
            'member_role_ids' => [],
            'overflow' => 'queue',
        ]);

        $number = PhoneNumber::factory()->create([
            'connection_id' => $connection->id,
            'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'ring_group_id' => $group->id,
        ]);

        return [$number, $group];
    }

    /** @return array{0: string, 1: string, 2: string, 3: string} */
    private function queueFixture(array $tenant, bool $available): array
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $available) {
            [$number, $group] = $this->queueLine($tenant, $available);

            return [
                (string) $tenant['org']->id,
                (string) $number->id,
                (string) $group->id,
                (string) $number->connection_id,
            ];
        });
    }

    /**
     * The hold loop as Twilio would request it: signed, or it is a 403.
     *
     * Signed rather than middleware-free on purpose. This endpoint is the one
     * Twilio hits most often of any callback we own — once per music loop per
     * waiting caller — so an unsigned one is the cheapest denial of service in
     * the module, and a test that skipped the check could not tell a route
     * that verifies from one that does not.
     *
     * @param  array<string, mixed>  $params
     */
    private function postWait(
        string $org,
        string $connection,
        string $number,
        string $group,
        array $params,
    ): TestResponse {
        $url = url("/api/v1/phone/queue/{$org}/{$connection}/{$number}/{$group}");
        $signable = array_map(static fn ($value) => (string) $value, $params);

        return $this->post($url, $signable, [
            'X-Twilio-Signature' => (new RequestValidator(self::AUTH_TOKEN))
                ->computeSignature($url, $signable),
        ]);
    }

    private function connection(array $tenant): PhoneConnection
    {
        return PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $tenant['org']->id,
            'auth_token' => self::AUTH_TOKEN,
        ]);
    }

    private function member(array $tenant, string $name): User
    {
        $role = Role::query()->firstOrCreate(
            ['organization_id' => $tenant['org']->id, 'name' => 'Queue members'],
            ['is_system' => false],
        );

        return User::create([
            'organization_id' => $tenant['org']->id,
            'role_id' => $role->id,
            'name' => $name,
            'email' => strtolower($name).'@'.$tenant['org']->slug.'.test',
            'password' => 'a-real-password',
            'is_active' => true,
        ]);
    }

    /**
     * The main voice webhook, signed the same way.
     *
     * @param  array<string, mixed>  $params
     */
    private function postVoice(
        string $org,
        string $connection,
        array $params,
    ): TestResponse {
        $url = url("/api/v1/phone/voice/{$org}/{$connection}");
        $signable = array_map(static fn ($value) => (string) $value, $params);

        return $this->post($url, $signable, [
            'X-Twilio-Signature' => (new RequestValidator(self::AUTH_TOKEN))
                ->computeSignature($url, $signable),
        ]);
    }

    private function queueNameIn(string $xml): string
    {
        preg_match('/<Enqueue[^>]*>([^<]+)</', $xml, $matches);

        return trim($matches[1] ?? '');
    }
}
