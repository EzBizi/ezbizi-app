<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\PhoneNumberGrant;
use App\Models\PhoneVoicemailBox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A voicemail box that messages actually reach.
 *
 * ── The report ───────────────────────────────────────────────────
 *
 * Jose clicked "A-1 Chimney Mailbox" and saw nothing, while "All voicemails"
 * listed a screen full. Measured on production before changing anything:
 *
 *     box A-1 Chimney Mailbox   own-lines 0
 *     connection default        (none)
 *     lines with no box of their own   11
 *
 * So the screen was telling the truth. Nothing pointed at that box — not a
 * line, not the company — and eleven lines had been taking messages under no
 * box at all. Making a box did not attach it to anything, and nothing where
 * the box was made said so.
 *
 * Two separate faults come out of that, and each has its own test below: a
 * new box that catches nothing, and a filter that ignored the company default
 * even when one WAS set.
 */
class PhoneVoicemailBoxReachTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: PhoneConnection, 2: PhoneNumber, 3: \App\Models\User} */
    private function line(): array
    {
        $t = $this->provisionTenant();
        $desk = $this->userWithRole($t, 'Voicemail desk', ['phone.view', 'phone.edit']);

        [$connection, $number] = $this->inTenant($t['org'], function () use ($t, $desk) {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'e164' => '+13055550121',
                'voicemail_box_id' => null,
            ]);

            PhoneNumberGrant::create([
                'organization_id' => $t['org']->id,
                'number_id' => $number->id,
                'role_id' => $desk->role_id,
            ]);

            return [$connection, $number];
        });

        return [$t, $connection, $number, $desk];
    }

    private function voicemailOn(array $t, PhoneNumber $number): PhoneCall
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
        ]));
    }

    /**
     * The first box a company makes is the box its calls go to.
     *
     * Otherwise it is a box nothing points at: a line's box is
     * `number ?? connection`, so a fresh one catches nothing until somebody
     * visits two other screens neither of which is mentioned where the box was
     * made.
     */
    public function test_the_first_box_becomes_the_companys(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $id = $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'A-1 Chimney Mailbox',
        ])->assertCreated()->json('data.id');

        $this->assertSame(
            $id,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
            'the company still sends voicemail nowhere, so this box catches '
            .'nothing and the screen shows an empty list',
        );
    }

    /**
     * A SECOND box does not steal the company's choice.
     *
     * The convenience above must never overwrite a decision somebody has made,
     * or making a box for one line silently redirects every other line.
     */
    public function test_a_second_box_leaves_the_choice_alone(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $first = $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Main',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Sales',
        ])->assertCreated();

        $this->assertSame(
            $first,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
        );
    }

    /**
     * Deleting the company's box hands the job to another, not to nothing.
     *
     * `destroy()` repointed the LINES and forgot the connection, so removing a
     * default left every line inheriting an id that resolves to nothing — the
     * same box-nothing-points-at defect from the other end, and this time
     * wearing a setting that looks like somebody chose it.
     */
    public function test_deleting_the_default_box_hands_it_to_the_next(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $first = $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Main',
        ])->assertCreated()->json('data.id');

        $second = $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Sales',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($desk)
            ->deleteJson('/api/v1/phone/voicemail-boxes/'.$first)
            ->assertOk();

        $this->assertSame(
            $second,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
            'the company still points at a box that no longer exists',
        );
    }

    /**
     * And with nothing left to hand it to, it says so rather than dangling.
     *
     * The control for the test above: a reassignment that always found
     * "another box" would have to invent one, and a stale id and a null read
     * identically at the routing end while only one of them is honest.
     */
    public function test_deleting_the_last_box_leaves_the_company_pointing_at_nothing(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $only = $this->signedInAs($desk)->postJson('/api/v1/phone/voicemail-boxes', [
            'connection_id' => $connection->id,
            'name' => 'Only',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($desk)
            ->deleteJson('/api/v1/phone/voicemail-boxes/'.$only)
            ->assertOk();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
        );
    }

    /**
     * A box made before any of this catches messages without being asked.
     *
     * Jose's A-1 Chimney Mailbox predates the rule that a new box claims the
     * company default, so that rule left exactly the box which reported the
     * bug still catching nothing. His words: it should be set automatically.
     *
     * This drives the backfill over the state production was actually in — a
     * box, and a connection pointing nowhere.
     */
    public function test_an_older_box_is_adopted_by_its_company(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $box = $this->inTenant($t['org'], function () use ($t, $connection) {
            $box = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'A-1 Chimney Mailbox',
            ]);

            // The state the create rule cannot reach: a box that exists and a
            // company pointed at nothing.
            $connection->forceFill(['default_voicemail_box_id' => null])->save();

            return $box;
        });

        $this->runBackfill();

        $this->assertSame(
            $box->id,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
            'the box still catches nothing and Jose still has to find a screen',
        );
    }

    /**
     * And a company that has chosen keeps its choice.
     *
     * The control. A backfill that simply wrote the oldest box everywhere
     * would pass the test above and silently redirect every company that had
     * deliberately picked a different one.
     */
    public function test_the_backfill_does_not_overwrite_a_choice(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $chosen = $this->inTenant($t['org'], function () use ($t, $connection) {
            // Two boxes, and the one the company wants is NOT the oldest.
            PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Older',
                'created_at' => now()->subDay(),
            ]);

            $chosen = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'The one they picked',
            ]);

            $connection->forceFill(['default_voicemail_box_id' => $chosen->id])->save();

            return $chosen;
        });

        $this->runBackfill();

        $this->assertSame(
            $chosen->id,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
        );
    }

    /**
     * A company pointed at a box that has been deleted is repointed.
     *
     * That id resolves to nothing, which is the same as having no box while
     * looking like a setting somebody made — the worse of the two, because it
     * stops anybody looking.
     */
    public function test_a_stale_default_is_repointed(): void
    {
        [$t, $connection, , $desk] = $this->line();

        $box = $this->inTenant($t['org'], function () use ($t, $connection) {
            $box = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Live box',
            ]);

            $connection->forceFill([
                'default_voicemail_box_id' => (string) Str::uuid(),
            ])->save();

            return $box;
        });

        $this->runBackfill();

        $this->assertSame(
            $box->id,
            $this->inTenant($t['org'], fn () => $connection->fresh()->default_voicemail_box_id),
        );
    }

    /**
     * The migration itself, run again over the current data.
     *
     * RefreshDatabase has already migrated, and it ran before these fixtures
     * existed — so a test that trusted that run would be asserting against an
     * empty table and could not fail.
     */
    private function runBackfill(): void
    {
        $file = database_path(
            'migrations/2026_09_10_170000_point_every_company_at_a_voicemail_box.php',
        );

        $this->assertFileExists($file, 'the backfill this test names is not there');

        (require $file)->up();
    }

    /**
     * Opening a box shows the messages that reached it through the COMPANY.
     *
     * A line's box is `number ?? connection` and routing obeys that, but this
     * filter read the column alone — so a company that set its box centrally
     * rather than on every line had every box show empty while All voicemails
     * showed the messages sitting in them. Two answers to "which box does this
     * line use", and the screen asked the one that decides nothing.
     */
    public function test_a_box_shows_messages_that_reached_it_by_default(): void
    {
        [$t, $connection, $number, $desk] = $this->line();

        $box = $this->inTenant($t['org'], fn () => PhoneVoicemailBox::query()->create([
            'organization_id' => $t['org']->id,
            'connection_id' => $connection->id,
            'name' => 'Company box',
        ]));

        $this->inTenant($t['org'], fn () => $connection
            ->forceFill(['default_voicemail_box_id' => $box->id])->save());

        $this->voicemailOn($t, $number);

        $rows = $this->signedInAs($desk)
            ->getJson('/api/v1/phone/calls?box_id='.$box->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(
            1,
            $rows,
            'the message reached this box through the company default and the '
            .'box shows nothing, which is what Jose reported',
        );
    }

    /**
     * A line that names its OWN box still wins.
     *
     * The control for the test above: without it, a filter that simply matched
     * every line on the connection would pass, and every box would show every
     * message.
     */
    public function test_a_line_with_its_own_box_does_not_leak_into_the_default(): void
    {
        [$t, $connection, $number, $desk] = $this->line();

        [$company, $its] = $this->inTenant($t['org'], function () use ($t, $connection, $number) {
            $company = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'Company box',
            ]);

            $its = PhoneVoicemailBox::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'name' => 'This line only',
            ]);

            $connection->forceFill(['default_voicemail_box_id' => $company->id])->save();
            $number->forceFill(['voicemail_box_id' => $its->id])->save();

            return [$company, $its];
        });

        $this->voicemailOn($t, $number);

        $this->assertCount(
            0,
            $this->signedInAs($desk)
                ->getJson('/api/v1/phone/calls?box_id='.$company->id)
                ->assertOk()->json('data'),
            'a message for a line with its own box turned up in the company '
            .'box as well, so both boxes claim it',
        );

        $this->assertCount(
            1,
            $this->signedInAs($desk)
                ->getJson('/api/v1/phone/calls?box_id='.$its->id)
                ->assertOk()->json('data'),
        );
    }
}
