<?php

namespace Tests\Feature;

use App\Models\AutomationEvent;
use App\Models\Mailbox;
use App\Models\MessageDealt;
use App\Models\MessageSeen;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The rule that marks a message handled — and what it must NOT do.
 *
 * ═══════════════════════════════════════════════════════════════════
 *  This action exists instead of the one that was declared for a
 *  year and never implemented.
 *
 *  `email.mark_seen` was in the catalogue with the label "Mark a
 *  message as dealt with" — key and label already disagreeing. The
 *  obvious implementation was to write a `message_seen` row, and
 *  that would have been the expensive mistake: that table is per
 *  person and is what "Seen by Jenny" reads. A rule has no eyes.
 *  Recording that it read something would put a colleague's name
 *  against mail nobody looked at, and the next colleague would skip
 *  a customer on the strength of it.
 *
 *  So handled and read are separate facts at separate grains, and
 *  the tests below assert the separation rather than assuming it.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Driven through ActionRunner, not through the writer ──────────
 *
 * A test that called DealtState directly would prove the writer works and
 * nothing about whether a rule can reach it. The registry, the allowlist and
 * the dispatcher are all places this can be declared and still be unreachable,
 * which is the failure this module has already found twice.
 */
class EmailAutomationActionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function inbox(array $t): Mailbox
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $mailbox = Mailbox::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => 'organization',
                'scope_id'        => $t['org']->id,
                'address'         => 'info@a-1chimney.com',
                'display_name'    => 'Info',
                'provider'        => 'gmail_oauth',
            ]);

            $mailbox->connect('a-refresh-token');
            $mailbox->save();

            return $mailbox;
        });
    }

    /** @return array{0: array, 1: Mailbox, 2: array} */
    private function fire(array $payload = ['message_id' => 'm-1']): array
    {
        $t       = $this->provisionTenant('A1-Group');
        $mailbox = $this->inbox($t);

        $result = $this->inTenant($t['org'], function () use ($mailbox, $payload) {
            $event = AutomationEvent::create([
                'event_key'    => 'email.received',
                'subject_type' => Mailbox::class,
                'subject_id'   => $mailbox->id,
                'payload'      => $payload,
                'occurred_at'  => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(
                ['type' => 'message.mark_dealt'],
                $event,
                $mailbox->fresh(),
            );
        });

        return [$result, $mailbox, $t];
    }

    public function test_a_rule_can_mark_a_message_dealt_with(): void
    {
        [$result, $mailbox, $t] = $this->fire();

        $this->assertTrue($result['ok']);
        $this->assertSame('message.mark_dealt', $result['type']);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $row = MessageDealt::where('mailbox_id', $mailbox->getKey())->first();

            $this->assertNotNull($row, 'The rule reported success and wrote nothing.');
            $this->assertSame('m-1', $row->provider_message_id);

            // Null, not a user: a rule has no person behind it, and the screen
            // says "an automation" rather than leaving a name blank.
            $this->assertNull($row->dealt_by_user_id);
        });
    }

    /**
     * THE ONE THIS FEATURE EXISTS TO GET RIGHT.
     *
     * If a rule ever writes a `message_seen` row, "Seen by" starts asserting
     * that a person read mail nobody read.
     */
    public function test_a_rule_never_records_that_somebody_read_it(): void
    {
        [$result, $mailbox, $t] = $this->fire();

        $this->assertTrue($result['ok']);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(
                0,
                MessageSeen::where('mailbox_id', $mailbox->getKey())->count(),
                'An automation recorded that somebody READ a message. A rule has no eyes, '
                .'and "Seen by" is a claim about a person.',
            );

            // The control: it did do its actual job, so the zero above is a
            // separation rather than the action having done nothing at all.
            $this->assertSame(1, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /** Running twice is one fact, not two rows and not a failure. */
    public function test_running_twice_is_idempotent(): void
    {
        [$first, $mailbox, $t] = $this->fire();

        $second = $this->inTenant($t['org'], function () use ($mailbox) {
            $event = AutomationEvent::create([
                'event_key'    => 'email.received',
                'subject_type' => Mailbox::class,
                'subject_id'   => $mailbox->id,
                'payload'      => ['message_id' => 'm-1'],
                'occurred_at'  => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(
                ['type' => 'message.mark_dealt'],
                $event,
                $mailbox->fresh(),
            );
        });

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertStringContainsString('already', $second['detail']);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(1, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /**
     * A trigger with no message names nothing to mark, and says which ones do.
     *
     * `email.mailbox_broken` carries a mailbox and no message. Marking "the
     * mailbox" dealt with is meaningless, and guessing a message would be
     * worse — so it refuses with the remedy, which is to move the rule.
     */
    public function test_an_event_with_no_message_refuses_and_says_where_the_action_belongs(): void
    {
        [$result, $mailbox, $t] = $this->fire(payload: ['address' => 'info@a-1chimney.com']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('an email arrived', $result['detail']);

        $this->inTenant($t['org'], function () use ($mailbox) {
            $this->assertSame(0, MessageDealt::where('mailbox_id', $mailbox->getKey())->count());
        });
    }

    /**
     * The catalogue offers it, which is the half that makes it reachable.
     *
     * A rule author picks from `action_types`. An action implemented and not
     * offered is an action nobody can choose; one offered and not implemented
     * saves and then fails on every run — this module shipped the second for a
     * year as `email.mark_seen`.
     */
    public function test_the_action_is_offered_and_implemented(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $types = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/catalogue')
            ->assertOk()
            ->json('data.action_types');

        $keys = array_column($types, 'key');

        $this->assertContains('message.mark_dealt', $keys);

        // And the key it replaced is gone, so nobody can still choose the one
        // that was never built.
        $this->assertNotContains('email.mark_seen', $keys);
        $this->assertNotContains('mark_seen', $keys);
    }
}
