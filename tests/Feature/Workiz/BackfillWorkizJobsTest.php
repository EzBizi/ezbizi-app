<?php

namespace Tests\Feature\Workiz;

use App\Models\AutomationEvent;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `ezbizi:backfill-workiz`: A-1's jobs from before the mirror began, read from
 * the v1 API (docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md, "Old jobs").
 *
 * Workiz is faked the way it was measured: `offset` is a page number, page 0 is
 * the farthest future, and `start_date` is the oldest date the pages reach.
 */
class BackfillWorkizJobsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $connection;

    /** Page number => the jobs Workiz answers with, or an HTTP status it refuses with. */
    private array $pages = [];

    /** The page numbers asked for, in order. */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->t = $this->provisionTenant('Alpha');
        $this->connection = $this->connect($this->t['franchise']);

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $this->asked[] = (int) $query['offset'];
            $page = $this->pages[(int) $query['offset']] ?? [];

            return is_int($page)
                ? Http::response(['flag' => false, 'msg' => 'Account reached Api Quotas'], $page)
                : Http::response(['flag' => true, 'has_more' => count($page) === WorkizJobs::PAGE, 'data' => $page]);
        });
    }

    private function connect(Franchise $branch): SocialConnection
    {
        return $this->inTenant($this->t['org'], function () use ($branch) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $branch->name,
            ]);
            $c->connect(['api_token' => 'api_good', 'refresh_token' => 'api_good']);
            $c->save();

            return $c;
        });
    }

    /** Workiz jobs, each for its own client. */
    private function jobs(string $prefix, int $count, array $over = []): array
    {
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');

        return array_map(
            fn (int $i) => array_merge($payload, ['UUID' => $prefix.$i, 'ClientId' => 5000 + $i], $over),
            range(1, $count),
        );
    }

    private function mirrored(): int
    {
        return $this->inTenant($this->t['org'], fn () => WorkizJob::query()->count());
    }

    public function test_it_reads_back_to_the_since_date_and_lands_only_jobs_the_mirror_lacks(): void
    {
        $this->pages = [0 => $this->jobs('A', WorkizJobs::PAGE), 1 => $this->jobs('B', 3)];

        // The minute sync already mirrors this one; it owns it.
        $this->inTenant($this->t['org'], fn () => WorkizJob::land($this->connection, $this->pages[0][0]));
        $before = $this->inTenant($this->t['org'], fn () => WorkizJob::query()->sole()->synced_at->toDateTimeString());
        $this->travel(5)->minutes();

        $this->artisan('ezbizi:backfill-workiz', ['--since' => '2024-01-01'])
            ->assertSuccessful()
            ->expectsOutputToContain('102 landed');

        $this->assertSame(WorkizJobs::PAGE + 3, $this->mirrored());
        $this->assertSame($before, $this->inTenant($this->t['org'], fn () => WorkizJob::query()->where('remote_id', 'A1')->sole()->synced_at->toDateTimeString()));
        $this->assertSame([0, 1], $this->asked);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'start_date=2024-01-01'));

        // Paced: a pause between pages, to leave the minute sync its quota.
        Sleep::assertSleptTimes(1);
    }

    public function test_it_stops_on_the_first_refusal_and_resumes_a_page_back(): void
    {
        $this->pages = [0 => $this->jobs('A', WorkizJobs::PAGE), 1 => $this->jobs('B', WorkizJobs::PAGE), 2 => 429];

        $this->artisan('ezbizi:backfill-workiz')->assertFailed()->expectsOutputToContain('429');

        $this->assertSame([0, 1, 2], $this->asked, 'nothing is asked after a refusal');
        $this->assertSame(2 * WorkizJobs::PAGE, $this->mirrored());

        // The quota is back.
        $this->asked = [];
        $this->pages[2] = $this->jobs('C', 4);

        $this->artisan('ezbizi:backfill-workiz')->assertSuccessful();

        // One page back, not from the start: a job added or removed between runs shifts every page by a row.
        $this->assertSame([1, 2], $this->asked);
        $this->assertSame(2 * WorkizJobs::PAGE + 4, $this->mirrored());
    }

    public function test_a_run_reads_at_most_its_budget_and_a_finished_branch_is_left_alone(): void
    {
        $this->pages = [0 => $this->jobs('A', WorkizJobs::PAGE), 1 => $this->jobs('B', WorkizJobs::PAGE), 2 => $this->jobs('C', 5)];

        $this->artisan('ezbizi:backfill-workiz', ['--pages' => 2])->assertSuccessful()->expectsOutputToContain('Not finished');
        $this->assertSame([0, 1], $this->asked);

        $this->asked = [];
        $this->artisan('ezbizi:backfill-workiz')->assertSuccessful()->expectsOutputToContain('Every branch is done');
        $this->assertSame([1, 2], $this->asked);

        $this->asked = [];
        $this->artisan('ezbizi:backfill-workiz')->assertSuccessful();
        $this->assertSame([], $this->asked, 'a finished branch is not read again');

        $this->artisan('ezbizi:backfill-workiz', ['--restart' => true])->assertSuccessful();
        $this->assertSame([0, 1, 2], $this->asked);
    }

    public function test_old_done_jobs_it_lands_raise_no_automation_event(): void
    {
        $this->pages = [0 => $this->jobs('A', 2, [
            'Status' => 'Done',
            'JobDateTime' => '2024-05-01 10:00:00',
            'LastStatusUpdate' => '2024-05-01 15:00:00',
        ])];

        $this->artisan('ezbizi:backfill-workiz')->assertSuccessful();
        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $jobs = $this->inTenant($this->t['org'], fn () => Job::query()->get());
        $this->assertCount(2, $jobs);
        $this->assertSame(['Done'], $jobs->pluck('status')->unique()->values()->all());
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => AutomationEvent::query()->count()));
    }
}
