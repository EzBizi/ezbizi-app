<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Which lines are busy, on the list the Dial picker reads.
 *
 * ── Why this had to exist ────────────────────────────────────────
 *
 * The Calling as picker offers every line the reader can see, which includes
 * lines other people are mid-call on. Dialling out under one of those puts a
 * second call on a number somebody is already using and sends the callback to
 * them. Nothing on screen said so, because the list is built from
 * `phone_numbers` and "busy" is a fact about `phone_calls`.
 */
class PhoneLineInUseTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneNumber, 2: PhoneNumber} */
    private function twoLines(): array
    {
        $t = $this->provisionTenant();

        [$mine, $theirs] = $this->inTenant($t['org'], function () use ($t) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            return [
                PhoneNumber::factory()->create([
                    'connection_id' => $connection->id,
                    'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id,
                    'e164' => '+13055550109',
                    'label' => 'Mine',
                ]),
                PhoneNumber::factory()->create([
                    'connection_id' => $connection->id,
                    'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id,
                    'e164' => '+13055550110',
                    'label' => 'Ana',
                ]),
            ];
        });

        return [$t, $mine, $theirs];
    }

    private function callOn(
        array $t,
        PhoneNumber $number,
        string $status,
        ?string $userId = null,
    ): PhoneCall {
        return $this->inTenant($t['org'], fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => $status,
            'user_id' => $userId,
        ]));
    }

    /** @return array<string, array<string, mixed>> the picker's rows, by label */
    private function lines(array $t, $user): array
    {
        $rows = $this->signedInAs($user)
            ->getJson('/api/v1/phone/numbers')
            ->assertOk()
            ->json('data');

        return collect($rows)->keyBy('label')->all();
    }

    public function test_a_line_somebody_is_talking_on_is_marked_busy(): void
    {
        [$t, $mine, $theirs] = $this->twoLines();
        $ana = $this->userWithRole($t, 'Ana', ['phone.view']);
        $me = $this->userWithRole($t, 'Desk', ['phone.view']);

        $this->callOn($t, $theirs, PhoneCall::STATUS_IN_PROGRESS, $ana->id);

        $lines = $this->lines($t, $me);

        $this->assertTrue($lines['Ana']['on_call']);
        $this->assertSame('Ana', $lines['Ana']['on_call_with']);

        // The control: the OTHER line is free, so "busy" is a fact about one
        // line and not a flag the endpoint sets on everything.
        $this->assertFalse($lines['Mine']['on_call']);
        $this->assertNull($lines['Mine']['on_call_with']);
    }

    /** A line that is ringing is in use too — it is about to be answered. */
    public function test_a_ringing_line_counts_as_in_use(): void
    {
        [$t, , $theirs] = $this->twoLines();
        $me = $this->userWithRole($t, 'Desk ringing', ['phone.view']);

        $this->callOn($t, $theirs, PhoneCall::STATUS_RINGING);

        $lines = $this->lines($t, $me);

        $this->assertTrue($lines['Ana']['on_call']);

        // Nobody's name: an inbound call still ringing a group belongs to no
        // one yet, and naming a person would be a guess.
        $this->assertNull($lines['Ana']['on_call_with']);
    }

    /**
     * A call that has ended does not hold the line.
     *
     * The whole value of this is that it goes away again. A busy flag derived
     * from any call ever placed would mark every line in the business
     * permanently unusable, and would look exactly like a working feature on
     * the day it shipped.
     */
    public function test_a_finished_call_leaves_the_line_free(): void
    {
        [$t, , $theirs] = $this->twoLines();
        $me = $this->userWithRole($t, 'Desk finished', ['phone.view']);

        foreach ([
            PhoneCall::STATUS_COMPLETED,
            PhoneCall::STATUS_NO_ANSWER,
            PhoneCall::STATUS_BUSY,
            PhoneCall::STATUS_FAILED,
            PhoneCall::STATUS_CANCELED,
            PhoneCall::STATUS_BLOCKED,
        ] as $status) {
            $this->callOn($t, $theirs, $status);
        }

        $this->assertFalse($this->lines($t, $me)['Ana']['on_call']);
    }

    /**
     * Asked twice in one test, and the second answer is not the first.
     *
     * A route keeps ONE controller instance for every request in a test, so a
     * map memoised on the controller would make this read the earlier answer.
     * That is not a hypothetical: this module has already shipped a memoised
     * registry that went stale exactly this way.
     */
    public function test_the_answer_is_recomputed_per_request(): void
    {
        [$t, , $theirs] = $this->twoLines();
        $me = $this->userWithRole($t, 'Desk twice', ['phone.view']);

        $this->assertFalse($this->lines($t, $me)['Ana']['on_call']);

        $call = $this->callOn($t, $theirs, PhoneCall::STATUS_IN_PROGRESS);
        $this->assertTrue($this->lines($t, $me)['Ana']['on_call']);

        $this->inTenant($t['org'], fn () => $call
            ->forceFill(['status' => PhoneCall::STATUS_COMPLETED])->save());

        $this->assertFalse($this->lines($t, $me)['Ana']['on_call']);
    }

    /** Every line the reader can see is offered, not only their own. */
    public function test_the_list_offers_every_line_in_reach(): void
    {
        [$t] = $this->twoLines();
        $me = $this->userWithRole($t, 'Desk all', ['phone.view']);

        $labels = array_keys($this->lines($t, $me));

        sort($labels);
        $this->assertSame(['Ana', 'Mine'], $labels);
    }
}
