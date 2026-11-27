<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Colleagues messaging each other inside EzBizi.
 *
 * Not SMS and not customer messaging: both ends are staff, and it never leaves
 * the organization.
 */
class StaffMessagesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function colleague(array $t, string $name): User
    {
        return $this->inTenant($t['org'], function () use ($t, $name) {
            $role = Role::query()->firstOrCreate(
                ['organization_id' => $t['org']->id, 'name' => 'Staff'],
                ['is_system' => false],
            );

            return User::query()->create([
                'organization_id' => $t['org']->id,
                'role_id' => $role->id,
                'name' => $name,
                'email' => strtolower($name).'@'.$t['org']->slug.'.test',
                'password' => 'a-real-password',
                'is_active' => true,
            ]);
        });
    }

    /**
     * A thread is the PAIR, not one direction.
     *
     * Whichever of them wrote it, it belongs in the same list — a reply that
     * appeared in its own separate conversation would not be a conversation.
     */
    /** Audit 11-27: a busy thread shows the NEWEST 200, oldest first. */
    public function test_a_long_thread_shows_the_newest_messages(): void
    {
        $t = $this->provisionTenant();
        $bar = $this->colleague($t, 'Bar');
        $ana = $this->colleague($t, 'Ana');
        $this->inTenant($t['org'], function () use ($t, $bar, $ana) {
            foreach (range(1, 205) as $i) {
                StaffMessage::query()->forceCreate([
                    'organization_id' => $t['org']->id, 'sender_id' => $bar->id, 'recipient_id' => $ana->id,
                    'body' => "m{$i}", 'created_at' => now()->subMinutes(300 - $i),
                ]);
            }
        });

        $bodies = array_column((array) $this->signedInAs($ana)->getJson('/api/v1/staff-messages?with='.$bar->id)->assertOk()->json('data'), 'body');
        $this->assertCount(200, $bodies);
        $this->assertSame('m6', $bodies[0]);
        $this->assertSame('m205', end($bodies));
    }

    public function test_a_thread_reads_both_directions(): void
    {
        $t = $this->provisionTenant();
        $bar = $this->colleague($t, 'Bar');
        $ana = $this->colleague($t, 'Ana');

        $this->signedInAs($bar)->postJson('/api/v1/staff-messages', [
            'to' => $ana->id,
            'body' => 'Is the van back?',
        ])->assertCreated();

        $this->signedInAs($ana)->postJson('/api/v1/staff-messages', [
            'to' => $bar->id,
            'body' => 'Ten minutes.',
        ])->assertCreated();

        $rows = $this->signedInAs($bar)
            ->getJson('/api/v1/staff-messages?with='.$ana->id)
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $rows);
        $this->assertSame('Is the van back?', $rows[0]['body']);
        $this->assertTrue($rows[0]['mine'], 'the sender did not recognise their own message');
        $this->assertSame('Ten minutes.', $rows[1]['body']);
        $this->assertFalse($rows[1]['mine']);
    }

    /**
     * Unread is counted PER SENDER, because the Team panel marks the person.
     *
     * One total tells somebody they have messages without telling them where
     * to look, which on a list of colleagues is most of the question.
     */
    public function test_unread_says_who_is_waiting_and_clears_on_opening(): void
    {
        $t = $this->provisionTenant();
        $bar = $this->colleague($t, 'Bar');
        $ana = $this->colleague($t, 'Ana');
        $jose = $this->colleague($t, 'Jose');

        foreach (['One', 'Two'] as $body) {
            $this->signedInAs($ana)->postJson('/api/v1/staff-messages', [
                'to' => $bar->id,
                'body' => $body,
            ])->assertCreated();
        }

        $this->signedInAs($jose)->postJson('/api/v1/staff-messages', [
            'to' => $bar->id,
            'body' => 'Three',
        ])->assertCreated();

        $unread = $this->signedInAs($bar)
            ->getJson('/api/v1/staff-messages/unread')
            ->assertOk();

        $this->assertSame(3, $unread->json('total'));
        $this->assertSame(2, $unread->json('data.'.$ana->id));
        $this->assertSame(1, $unread->json('data.'.$jose->id));

        // Opening the thread IS reading it.
        $this->signedInAs($bar)->getJson('/api/v1/staff-messages?with='.$ana->id)->assertOk();

        $after = $this->signedInAs($bar)
            ->getJson('/api/v1/staff-messages/unread')
            ->assertOk();

        $this->assertSame(1, $after->json('total'), 'opening one thread cleared another');
        $this->assertSame(1, $after->json('data.'.$jose->id));
    }

    /**
     * Reading my own sent messages must not mark them seen.
     *
     * "Seen" is the recipient's act. If opening a thread marked everything in
     * it read, the sender would always see their own message as read the
     * moment they sent it, and the indicator would mean nothing at all.
     */
    public function test_opening_a_thread_does_not_mark_my_own_messages_seen(): void
    {
        $t = $this->provisionTenant();
        $bar = $this->colleague($t, 'Bar');
        $ana = $this->colleague($t, 'Ana');

        $this->signedInAs($bar)->postJson('/api/v1/staff-messages', [
            'to' => $ana->id,
            'body' => 'Did you get this?',
        ])->assertCreated();

        // The sender re-opens their own thread.
        $rows = $this->signedInAs($bar)
            ->getJson('/api/v1/staff-messages?with='.$ana->id)
            ->assertOk()
            ->json('data');

        $this->assertNull(
            $rows[0]['read_at'],
            'the sender reading their own thread marked it seen by the recipient',
        );

        // Now the recipient opens it, which is what "seen" means.
        $this->signedInAs($ana)->getJson('/api/v1/staff-messages?with='.$bar->id)->assertOk();

        $rows = $this->signedInAs($bar)
            ->getJson('/api/v1/staff-messages?with='.$ana->id)
            ->assertOk()
            ->json('data');

        $this->assertNotNull($rows[0]['read_at']);
    }

    public function test_a_colleague_in_another_organization_is_not_reachable(): void
    {
        $mine = $this->provisionTenant();
        $theirs = $this->provisionTenant();

        $bar = $this->colleague($mine, 'Bar');
        $stranger = $this->colleague($theirs, 'Stranger');

        $this->signedInAs($bar)->postJson('/api/v1/staff-messages', [
            'to' => $stranger->id,
            'body' => 'Hello',
        ])->assertNotFound();

        $this->inTenant($mine['org'], fn () => $this->assertSame(
            0,
            StaffMessage::query()->count(),
        ));
    }

    public function test_messaging_yourself_is_refused(): void
    {
        $t = $this->provisionTenant();
        $bar = $this->colleague($t, 'Bar');

        $this->signedInAs($bar)->postJson('/api/v1/staff-messages', [
            'to' => $bar->id,
            'body' => 'Note to self',
        ])->assertStatus(422);
    }
}
