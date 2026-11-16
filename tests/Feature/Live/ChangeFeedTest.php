<?php

namespace Tests\Feature\Live;

use App\Models\Customer;
use App\Models\Job;
use App\Support\Live\ChangeFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET changes: the app-wide live feed (Jose, 2026-11-16: "The tab needs to be
 * live basically. This should go tbh for every module in the app").
 */
class ChangeFeedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function job(array $tenant, $franchise = null, string $title = 'Sweep'): Job
    {
        // A model write with no request, as a Workiz sync or webhook makes.
        $franchise ??= $tenant['franchise'];

        return $this->inTenant($tenant['org'], fn () => Job::factory()->create([
            'franchise_id' => $franchise->id, 'title' => $title,
            'customer_id' => Customer::create(['owning_franchise_id' => $franchise->id,
                'number' => 'C-'.bin2hex(random_bytes(3)), 'name' => 'Marta Quill'])->id,
        ]));
    }

    private function feed(?int $since, string $types = 'jobs'): array
    {
        return $this->getJson('/api/v1/changes?types='.$types.($since === null ? '' : '&since='.$since))
            ->assertOk()->json('data');
    }

    public function test_a_model_write_reaches_the_feed_and_the_cursor_moves_past_it(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);
        $start = $this->feed(null)['cursor'];

        $job = $this->job($t);
        $data = $this->feed($start);
        $this->assertSame([['type' => 'jobs', 'id' => $job->id, 'deleted' => false]], $data['changes']);
        $this->assertFalse($data['reload']);

        // Nothing since: empty, cursor unchanged.
        $quiet = $this->feed($data['cursor']);
        $this->assertSame([], $quiet['changes']);
        $this->assertSame($data['cursor'], $quiet['cursor']);

        // An edit then a delete is one entry, deleted.
        $this->inTenant($t['org'], function () use ($job) {
            $job->update(['title' => 'Changed']);
            $job->delete();
        });
        $this->assertSame([['type' => 'jobs', 'id' => $job->id, 'deleted' => true]], $this->feed($quiet['cursor'])['changes']);

        // A save that changes nothing writes nothing.
        $before = DB::table('change_events')->count();
        $this->inTenant($t['org'], fn () => Job::withTrashed()->find($job->id)->save());
        ChangeFeed::flush();
        $this->assertSame($before, DB::table('change_events')->count());
    }

    public function test_the_feed_keeps_to_tenant_permission_and_reach(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $sibling = $this->extraFranchise($alpha, 'Alpha F2');
        $branch = $this->userWithRole($alpha, 'Branch', ['jobs.view'], onlyAt: $alpha['franchise']);
        $noJobs = $this->userWithRole($alpha, 'Leads only', ['leads.view']);

        $this->signedInAs($branch);
        $since = $this->feed(null)['cursor'];
        $mine = $this->job($alpha);
        $theirs = $this->job($alpha, $sibling);
        $this->job($beta);

        // Positive control and the sideways branch: own branch only.
        $this->signedInAs($branch);
        $this->assertSame([$mine->id], array_column($this->feed($since)['changes'], 'id'));

        // The admin reaches both branches, never Beta.
        $this->signedInAs($alpha['admin']);
        $ids = array_column($this->feed($since)['changes'], 'id');
        sort($ids);
        $want = [$mine->id, $theirs->id];
        sort($want);
        $this->assertSame($want, $ids);

        // No jobs permission: no jobs, even when asked for.
        $this->signedInAs($noJobs);
        $this->assertSame([], $this->feed($since)['changes']);
    }

    public function test_more_than_a_batch_says_reload(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);
        $since = $this->feed(null)['cursor'];
        $rows = [];
        for ($i = 0; $i < ChangeFeed::CAP + 1; $i++) {
            $rows[] = ['organization_id' => $t['org']->id, 'type' => 'jobs', 'record_id' => "j{$i}",
                'deleted' => false, 'changed_at' => now()];
        }
        DB::table('change_events')->insert($rows);
        $data = $this->feed($since);
        $this->assertTrue($data['reload']);
        $this->assertSame([], $data['changes']);
        $this->assertGreaterThan($since, $data['cursor']);
    }

    public function test_prune_drops_rows_older_than_a_day(): void
    {
        $t = $this->provisionTenant('Alpha');
        DB::table('change_events')->insert([
            ['organization_id' => $t['org']->id, 'type' => 'jobs', 'record_id' => 'old', 'deleted' => false, 'changed_at' => now()->subDays(2)],
            ['organization_id' => $t['org']->id, 'type' => 'jobs', 'record_id' => 'new', 'deleted' => false, 'changed_at' => now()],
        ]);
        $this->artisan('ezbizi:prune-change-events')->assertSuccessful();
        $this->assertSame(['new'], DB::table('change_events')->pluck('record_id')->all());
    }
}
