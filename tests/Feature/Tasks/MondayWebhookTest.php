<?php

namespace Tests\Feature\Tasks;

use App\Jobs\SyncMondayItem;
use App\Models\MondayOutboxEntry;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\User;
use App\Support\Tasks\Monday\MondayWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesMonday;
use Tests\TestCase;

/**
 * Monday's webhooks (desk, 2026-11-12: "webhooks verified with the Signing
 * Secret"). Only a request signed with the app's Signing Secret moves
 * anything, and then only by re-reading the item from Monday.
 */
class MondayWebhookTest extends TestCase
{
    use BuildsTenants;
    use FakesMonday;
    use RefreshDatabase;

    private const SECRET = 'the-apps-signing-secret';

    private const URL = '/api/v1/integrations/monday/webhook';

    private array $tenant;

    private User $jose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:05:00');
        config(['services.monday.signing_secret' => self::SECRET]);
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards'], 'jose@a-1chimney.com');

        $file = tempnam(sys_get_temp_dir(), 'monday');
        file_put_contents($file, json_encode(['users' => [], 'boards' => [[
            'monday_id' => '100', 'name' => 'Jose Demasi', 'owner_email' => 'jose@a-1chimney.com', 'groups' => [], 'items' => [[
                'monday_id' => '11', 'name' => 'Call the insurer', 'group' => 'Open Tasks', 'created_at' => '2026-11-01T09:00:00Z',
                'updated_at' => '2026-11-12T10:00:00Z', 'status' => 'Working on it', 'people' => ['jose@a-1chimney.com'], 'updates' => [],
            ]],
        ]]]));
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $file, '--org' => $this->tenant['org']->id, '--as' => 'jose@a-1chimney.com'])->assertSuccessful();
        @unlink($file);

        $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->tenant['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group',
                'setup' => ['monday' => ['as' => $this->jose->id]],
            ]);
            $c->connect(['access_token' => 'monday-token', 'scopes' => []]);
            $c->save();
        });

        // Monday now says: done.
        $this->monday['users'] = [['id' => '1', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com']];
        $this->monday['boards'] = ['100' => $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer', '2026-11-12T10:30:00Z', ['status' => 'Done']),
        ])];
        $this->fakeMonday();
        Carbon::setTestNow('2026-11-12 10:31:00');
    }

    private function event(): array
    {
        return ['event' => ['type' => 'update_column_value', 'boardId' => 100, 'pulseId' => 11, 'columnId' => 'status', 'userId' => 1,
            // What the body claims is never written: the item is read from Monday.
            'value' => ['label' => ['text' => 'Hacked']]]];
    }

    private function done(): bool
    {
        return (bool) $this->inTenant($this->tenant['org'], fn () => Task::query()->where('external_ref', 'monday:11')->first()->statusRow->is_done);
    }

    public function test_a_signed_event_rereads_the_item_from_monday(): void
    {
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1', 'userId' => 1, 'exp' => time() + 300], self::SECRET);

        $this->postJson(self::URL, $this->event(), ['Authorization' => $jwt])->assertOk()->assertJson(['queued' => 1]);

        $this->assertTrue($this->done());
        $this->assertNotEmpty($this->mondayQueries, 'the item was read from Monday');
    }

    /** Desk 11-18: a missed call moved to Done on Monday, whose clock did not move, still closes here on the webhook. */
    public function test_a_move_on_monday_that_does_not_bump_the_clock_arrives_on_the_webhook(): void
    {
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1', 'userId' => 1, 'exp' => time() + 300], self::SECRET);
        // Monday: same updated_at as we last took, but now in Completed Tasks.
        $this->monday['boards']['100']['items'][0] = $this->mondayItem('11', 'Call the insurer', '2026-11-12T10:30:00Z', ['group' => 'Completed Tasks']) + ['_board' => '100'];
        $this->inTenant($this->tenant['org'], fn () => Task::query()->where('external_ref', 'monday:11')->update(['external_updated_at' => '2026-11-12 10:30:00']));

        $move = ['event' => ['type' => 'move_pulse_into_group', 'boardId' => 100, 'pulseId' => 11, 'userId' => 1, 'triggerTime' => '2026-11-12T10:30:55.000Z']];
        $this->postJson(self::URL, $move, ['Authorization' => $jwt])->assertOk()->assertJson(['queued' => 1]);

        $this->assertSame('Completed Tasks', $this->inTenant($this->tenant['org'], fn () => Task::query()->with('group')->where('external_ref', 'monday:11')->first()->group->name));
    }

    /** Desk 11-18: a delete right after our push is never taken for our echo. */
    public function test_a_delete_within_the_echo_window_is_still_queued(): void
    {
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1', 'userId' => 1, 'exp' => time() + 300], self::SECRET);
        $this->inTenant($this->tenant['org'], fn () => Task::query()->where('external_ref', 'monday:11')->update(['external_synced_at' => '2026-11-12 10:30:30']));
        Queue::fake();
        $delete = ['event' => ['type' => 'delete_pulse', 'boardId' => 100, 'pulseId' => 11, 'userId' => 1, 'triggerTime' => '2026-11-12T10:30:20.000Z']];

        $this->postJson(self::URL, $delete, ['Authorization' => $jwt])->assertOk()->assertJson(['queued' => 1]);
    }

    /** Desk 11-18: our own push coming back is dropped at the door, not queued; a later change still is. */
    public function test_an_event_no_later_than_our_last_write_is_our_echo_and_is_not_queued(): void
    {
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1', 'userId' => 1, 'exp' => time() + 300], self::SECRET);
        $this->inTenant($this->tenant['org'], fn () => Task::query()->where('external_ref', 'monday:11')->update(['external_synced_at' => '2026-11-12 10:30:30']));
        $echo = $this->event();
        $echo['event']['triggerTime'] = '2026-11-12T10:30:20.000Z';
        Queue::fake();

        $this->postJson(self::URL, $echo, ['Authorization' => $jwt])->assertOk()->assertJson(['queued' => 0, 'echo' => true]);
        Queue::assertNothingPushed();

        $later = $this->event();
        $later['event']['triggerTime'] = '2026-11-12T10:30:50.000Z';
        $this->postJson(self::URL, $later, ['Authorization' => $jwt])->assertOk()->assertJson(['queued' => 1]);
        Queue::assertPushedOn('monday', SyncMondayItem::class);
    }

    /** Loop guard (Jose 11-16, tasks made here go to Monday): an item born on Monday is not made back on Monday. */
    public function test_an_item_made_on_monday_arrives_here_and_is_not_sent_back(): void
    {
        $this->monday['boards']['100'] = $this->mondayBoard('100', 'Jose Demasi', [
            $this->mondayItem('11', 'Call the insurer', '2026-11-12T10:30:00Z', ['status' => 'Done']),
            $this->mondayItem('12', 'Order the liner', '2026-11-12T10:30:00Z', []),
        ]);
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1', 'userId' => 1, 'exp' => time() + 300], self::SECRET);

        $this->postJson(self::URL, ['event' => ['type' => 'create_pulse', 'boardId' => 100, 'pulseId' => 12, 'userId' => 1]], ['Authorization' => $jwt])
            ->assertOk()->assertJson(['queued' => 1]);

        $this->inTenant($this->tenant['org'], function () {
            $this->assertSame('Order the liner', Task::query()->where('external_ref', 'monday:12')->sole()->title);
            $this->assertSame(0, MondayOutboxEntry::query()->count());
        });
        $this->artisan('ezbizi:monday-push')->assertSuccessful(); // FakesMonday fails the test on any mutation
    }

    /** The check this endpoint stands on: without it, any of these would move the task. */
    public function test_an_event_not_signed_with_the_apps_secret_changes_nothing(): void
    {
        $refused = [
            'no header' => null,
            'another secret' => MondayWebhookSignature::sign(['accountId' => 'acc1'], 'somebody-elses-secret'),
            'expired' => MondayWebhookSignature::sign(['accountId' => 'acc1', 'exp' => time() - 10], self::SECRET),
            'alg none' => rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=').'.'
                .rtrim(strtr(base64_encode('{"accountId":"acc1"}'), '+/', '-_'), '=').'.',
            'garbage' => 'Bearer not-a-token',
        ];
        foreach ($refused as $why => $header) {
            $this->postJson(self::URL, $this->event(), $header === null ? [] : ['Authorization' => $header])
                ->assertUnauthorized();
            $this->assertFalse($this->done(), $why);
        }
        $this->assertSame([], $this->mondayQueries, 'nothing was even read');
    }

    public function test_no_signing_secret_configured_refuses_everything(): void
    {
        config(['services.monday.signing_secret' => null]);
        $jwt = MondayWebhookSignature::sign(['accountId' => 'acc1'], '');

        $this->postJson(self::URL, $this->event(), ['Authorization' => $jwt])->assertUnauthorized();
        $this->assertFalse($this->done());
    }

    public function test_mondays_registration_challenge_is_echoed(): void
    {
        $this->postJson(self::URL, ['challenge' => 'abc123'])->assertOk()->assertExactJson(['challenge' => 'abc123']);
    }
}
