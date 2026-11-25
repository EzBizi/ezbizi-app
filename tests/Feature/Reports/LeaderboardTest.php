<?php

namespace Tests\Feature\Reports;

use App\Console\Commands\ScoreReportCalls;
use App\Models\CallReportDispatcherTag;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use App\Models\User;
use App\Support\Reports\Experimental\PerformancePeople;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Trey, approved by Jose 2026-11-25: the top three marked for New Client Calls,
 * the reader's own rank, and a tab with only the reader's own Performance.
 */
class LeaderboardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DATES = '?from=2026-10-13&to=2026-10-13';

    /** Tuesday: its week is Sunday 11 to Friday 16 October. */
    private const WEEK = '?week=2026-10-13';

    /** @var array<string, mixed> */
    private array $t;

    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $this->t = $this->provisionTenant('Alpha');

        // Before the grant runs, as on the box: the roles exist and hold nothing of it.
        foreach (['Di' => 'Dispatcher', 'Ed' => 'Remote Dispatcher', 'Fe' => 'Dispatch Supervisor', 'Gus' => 'Technician'] as $name => $role) {
            $user = $this->userWithRole($this->t, $role);
            $this->inTenant($this->t['org'], fn () => $user->forceFill(['name' => $name])->save());
            $this->users[$name] = $user;
        }
        (require base_path('database/migrations/2026_11_25_183512_grant_dispatchers_the_leaderboard.php'))->up();

        $this->inTenant($this->t['org'], function () {
            // Fe answered two scored calls: fewer than the three that rank.
            foreach (['Ada' => 9, 'Bo' => 8, 'Cy' => 7, 'Di' => 6, 'Ed' => 10, 'Fe' => 10] as $who => $score) {
                foreach (range(0, $who === 'Fe' ? 1 : 2) as $i) {
                    $id = "$who$i";
                    CallReportTranscript::query()->create(['organization_id' => $this->t['org']->id, 'call_id' => $id, 'transcript' => "$who said hello",
                        'source' => 'whisper', 'answered_by' => $who, 'call_started_at' => '2026-10-13 1'.$i.':00:00', 'caller_number' => '+1214555000'.$i]);
                    CallReportScore::query()->create(['organization_id' => $this->t['org']->id, 'call_id' => $id, 'score' => $score,
                        'checks' => array_fill_keys(ScoreReportCalls::CHECKS, true)]);
                }
                // Ed takes Existing clients: the best score, and off the board.
                CallReportDispatcherTag::query()->create(['organization_id' => $this->t['org']->id, 'person' => $who, 'tag' => $who === 'Ed' ? 'existing' : 'new']);
            }
        });

        // No new-client calls to judge a booking rate on: the score is the calls'.
        $people = ['Di' => ['person' => 'Di', 'answered' => 4], 'Ada' => ['person' => 'Ada', 'answered' => 9]];
        $this->mock(PerformancePeople::class, function ($mock) use ($people) {
            $mock->shouldReceive('for')->andReturn($people);
            $mock->shouldReceive('report')->andReturn(['people' => array_values($people), 'calls' => [
                ['id' => 'k1', 'answered_by' => 'Di', 'rang' => ['Di']],
                ['id' => 'k2', 'answered_by' => 'Ada', 'rang' => ['Ada', 'Di']],
                ['id' => 'k3', 'answered_by' => null, 'placed_by' => 'Di', 'rang' => []],
                ['id' => 'k4', 'answered_by' => 'Ada', 'rang' => ['Ada']],
            ]]);
        });
    }

    public function test_the_top_three_marked_new_and_the_readers_own_rank(): void
    {
        $board = $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard'.self::WEEK)->assertOk()->json('data');

        $this->assertSame(['from' => '2026-10-11', 'to' => '2026-10-16'], $board['week']);
        $this->assertSame(['Ada', 'Bo', 'Cy'], array_column($board['top'], 'person'), 'Ed handles Existing; Fe has too few calls');
        $this->assertSame([1, 2, 3], array_column($board['top'], 'rank'));
        $this->assertSame(['rank', 'person', 'user_id', 'score', 'calls_scored'], array_keys($board['top'][0]), 'three names and scores, nothing else of theirs');
        $this->assertSame(['person' => 'Di', 'rank' => 4, 'of' => 4, 'calls_scored' => 3, 'why' => null],
            array_diff_key($board['me'], ['score' => true]));

        $why = fn (string $who) => $this->signedInAs($this->users[$who])->getJson('/api/v1/leaderboard'.self::WEEK)->assertOk()->json('data.me');
        $this->assertSame([null, 'not_new'], [$why('Ed')['rank'], $why('Ed')['why']]);
        $this->assertSame([null, 'too_few_calls', 2], [$why('Fe')['rank'], $why('Fe')['why'], $why('Fe')['calls_scored']]);
    }

    /** Trey: "each week it would start over on sunday, and you can check previous weeks winners". */
    public function test_past_weeks_and_their_winners(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00', 'America/New_York'));
        $board = $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard')->assertOk()->json('data');
        $this->assertSame([['from' => '2026-10-18', 'to' => '2026-10-23'], []], [$board['week'], $board['top']], 'a new week starts empty');

        // The week before only: the one before that ended before AI scored calls.
        $weeks = $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard/weeks?limit=8')->assertOk()->json('data');
        $this->assertSame([['2026-10-11', '2026-10-16', ['Ada', 'Bo', 'Cy']]],
            array_map(fn ($w) => [$w['from'], $w['to'], array_column($w['top'], 'person')], $weeks));

        // Thursday and Friday have not come: read up to today, never a live day ahead.
        $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard/mine?from=2026-10-18&to=2026-10-23')->assertOk();
        app(PerformancePeople::class)->shouldHaveReceived('report')
            ->withArgs(fn ($from, $to) => $to->toDateString() === '2026-10-22')->once();
    }

    public function test_my_performance_is_the_readers_alone(): void
    {
        $mine = $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard/mine'.self::DATES)->assertOk()->json('data');

        $this->assertSame(['Di', 'Di', 'Di'], [$mine['person'], $mine['performance']['person'], $mine['scoring']['person']]);
        $this->assertArrayNotHasKey('rank', $mine['scoring'], 'Scoring\'s rank is among everybody');
        $this->assertSame(['k1', 'k2', 'k3'], array_column($mine['calls'], 'id'), 'answered, rang them, placed by them; not Ada\'s');

        // Asking for somebody else's calls still returns the reader's.
        $calls = $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard/mine/calls'.self::DATES.'&person=Ada')->assertOk()->json('data');
        $this->assertSame(['Di2', 'Di1', 'Di0'], array_column($calls, 'call_id'));
        $this->assertStringContainsString('Di said', $calls[0]['transcript']);
    }

    public function test_the_grant_reaches_the_dispatch_roles_only(): void
    {
        $this->inTenant($this->t['org'], function () {
            foreach (['Di', 'Ed', 'Fe'] as $who) {
                $this->assertTrue($this->users[$who]->fresh()->hasPermission('leaderboard.mine.view'), $who);
            }
            $this->assertFalse($this->users['Di']->fresh()->hasPermission('reports.scoring.view'), 'the board opens no Reports tab');
        });
        $this->signedInAs($this->users['Gus'])->getJson('/api/v1/leaderboard'.self::WEEK)->assertForbidden();
        $this->signedInAs($this->users['Gus'])->getJson('/api/v1/leaderboard/mine'.self::DATES)->assertForbidden();
        $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard?week=bad')->assertUnprocessable();
        $this->signedInAs($this->users['Di'])->getJson('/api/v1/leaderboard/mine?from=bad&to=2026-10-13')->assertUnprocessable();
    }
}
