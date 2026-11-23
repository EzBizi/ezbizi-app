<?php

namespace Tests\Feature\Reports;

use App\Console\Commands\ScoreReportCalls;
use App\Models\CallReportOverride;
use App\Models\CallReportPersonReview;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use App\Support\Reports\Experimental\PerformancePeople;
use App\Support\Reports\Scoring\ScoringWeights;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13: each employee's scores as cards, and a ranking. */
class ScoringReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_performance_alone_does_not_open_scoring(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'Dispatcher', ['reports.experimental.view']))
            ->getJson('/api/v1/reports/scoring?from=2026-10-13&to=2026-10-14')
            ->assertForbidden();
    }

    /** Jose, 2026-10-14: a person's latest 3 calls, searchable, each with its score. */
    public function test_a_persons_latest_three_scored_calls_and_a_search(): void
    {
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([
                ['c1', 'Ada', 8, '2026-10-13 14:00:00', '+12145550001', 'chimney sweep price'],
                ['c2', 'Ada', 6, '2026-10-13 15:00:00', '+12145550002', 'dryer vent'],
                ['c3', 'Ada', 7, '2026-10-13 16:00:00', '+12145550003', 'gas logs'],
                ['c4', 'Ada', 9, '2026-10-13 17:00:00', '+12145550004', 'masonry'],
                ['c1x', 'Ada', 3, '2026-10-13 13:00:00', '+12145550005', 'inspection'],
                ['b1', 'Bo', 5, '2026-10-13 18:00:00', '+12145550001', 'chimney'],
            ] as [$id, $who, $score, $at, $number, $words]) {
                CallReportTranscript::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => $words,
                    'source' => 'whisper', 'answered_by' => $who, 'call_started_at' => $at, 'caller_number' => $number, 'recording_id' => "rec-$id"]);
                CallReportScore::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'score' => $score, 'checks' => []]);
            }
        });

        $user = $this->userWithRole($t, 'Lead Manager', ['reports.scoring.view']);
        // Design 179: what was set by hand on a call rides along.
        $this->inTenant($t['org'], fn () => CallReportOverride::query()->create(['organization_id' => $t['org']->id,
            'call_id' => 'c4', 'field' => 'booking', 'value' => 'booked', 'set_by' => $user->id]));
        $get = fn (string $q) => $this->signedInAs($user)
            ->getJson('/api/v1/reports/scoring/calls?person=Ada&from=2026-10-13&to=2026-10-14'.$q)->assertOk()->json('data');

        $latest = $get('');
        // The latest three, then the worst; the best (c4, 9) is also the latest.
        $this->assertSame(['c4', 'c3', 'c2', 'c1x'], array_column($latest, 'call_id'));
        $this->assertSame([9, 7, 6, 3], array_column($latest, 'score'));
        $this->assertSame([['latest', 'best'], ['latest'], ['latest'], ['worst']], array_column($latest, 'tags'));
        $this->assertSame('rec-c4', $latest[0]['recording_id']);
        $this->assertSame(['booking' => ['value' => 'booked', 'by' => $user->name]], $latest[0]['set']);
        $this->assertNull($latest[1]['set']);

        $this->assertSame(['c1'], array_column($get('&q=214-555-0001'), 'call_id'), 'by number, only Ada\'s');
        $this->assertSame(['c3'], array_column($get('&q=gas'), 'call_id'), 'by words');
    }

    /**
     * Jose, 2026-11-04: Existing-client staff ranked first on "conversion" =
     * every job they created over their few new calls (6 on 1: 600%). Someone
     * who handles Existing, or answered under MIN_CALLS new calls, is judged
     * without it; the rest on booked out of answered new-client calls.
     */
    public function test_conversion_is_booked_new_calls_and_skips_existing_handlers(): void
    {
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            foreach (['Ada', 'Cris', 'Few'] as $p => $who) {
                foreach (range(0, 2) as $i) {
                    $id = "$who$i";
                    CallReportTranscript::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => 'x',
                        'source' => 'whisper', 'answered_by' => $who, 'call_started_at' => '2026-10-13 1'.$p.':0'.$i.':00']);
                    CallReportScore::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'score' => 7,
                        'checks' => array_fill_keys(ScoreReportCalls::CHECKS, true)]);
                }
            }
        });
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([
            'Ada' => ['new_client' => 10, 'booked_new' => 4, 'jobs' => 9, 'tag' => 'new'],
            'Cris' => ['new_client' => 5, 'booked_new' => 5, 'jobs' => 30, 'tag' => 'existing'],
            'Few' => ['new_client' => 1, 'booked_new' => 1, 'jobs' => 6, 'tag' => null],
        ]);

        $people = collect($this->signedInAs($t['admin'])->getJson('/api/v1/reports/scoring?from=2026-10-13&to=2026-10-13')
            ->assertOk()->json('data.people'))->keyBy('person');

        $this->assertSame(40, $people['Ada']['conversion']);
        $this->assertNull($people['Cris']['conversion'], 'handles Existing');
        $this->assertNull($people['Few']['conversion'], 'one new call is no rate');
    }

    /** Jose, 2026-11-04: the minimum is a setting, not a constant; raised, Ada's three calls rank her no more. */
    public function test_the_ranking_minimum_is_the_organizations_setting(): void
    {
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            foreach (['a1', 'a2', 'a3'] as $i => $id) {
                CallReportTranscript::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => 'x',
                    'source' => 'whisper', 'answered_by' => 'Ada', 'call_started_at' => '2026-10-13 1'.$i.':00:00']);
                CallReportScore::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'score' => 7,
                    'checks' => array_fill_keys(ScoreReportCalls::CHECKS, true)]);
            }
        });
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn([]);
        $ada = fn () => $this->signedInAs($t['admin'])->getJson('/api/v1/reports/scoring?from=2026-10-13&to=2026-10-13')->assertOk()->json('data');

        $this->assertSame(1, $ada()['people'][0]['rank']);
        // No user is called Ada: no photo, and nothing guessed.
        $this->assertNull($ada()['people'][0]['user_id']);
        // One active user with exactly that name: theirs (Jose, 2026-11-13).
        $this->inTenant($t['org'], fn () => $t['admin']->forceFill(['name' => 'Ada'])->save());
        $this->assertSame($t['admin']->id, $ada()['people'][0]['user_id']);

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('reports.scoring_min_calls', $t['org'], 5));
        $this->assertSame(5, $ada()['min_calls_to_rank']);
        $this->assertNull($ada()['people'][0]['rank']);
    }

    /** Jose, 2026-10-14: conversion counts most, every metric has a weight, and they sum to 100. */
    public function test_the_score_weighs_conversion_and_the_checks_by_the_saved_weights(): void
    {
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            foreach (['a1', 'a2', 'a3'] as $i => $id) {
                CallReportTranscript::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => 'x',
                    'source' => 'whisper', 'answered_by' => 'Ada', 'call_started_at' => '2026-10-13 1'.$i.':00:00']);
                CallReportScore::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'score' => 7,
                    'checks' => array_fill_keys(ScoreReportCalls::CHECKS, true)]);
            }
        });
        // Performance's People row for Ada: 4 new-client calls answered, 1 of
        // them booked. Her 3 jobs (some for existing clients) are not the rate.
        $this->mock(PerformancePeople::class)->shouldReceive('for')->andReturn(['Ada' => ['new_client' => 4, 'booked_new' => 1, 'jobs' => 3]]);

        $admin = $this->signedInAs($t['admin']);
        $ada = fn () => $admin->getJson('/api/v1/reports/scoring?from=2026-10-13&to=2026-10-13')->assertOk()->json('data.people.0');

        // Defaults: conversion 50 at 25%, quality 15 at 70%, every check met (35 at 100%):
        // (1250 + 1050 + 3500) / 100 = 58% -> 5.8.
        $this->assertSame(25, $ada()['conversion']);
        $this->assertEquals(5.8, $ada()['score']);

        $all = array_fill_keys(ScoringWeights::metrics(), 0);
        $admin->putJson('/api/v1/reports/scoring/weights', ['weights' => ['conversion' => 50] + $all])
            ->assertUnprocessable()->assertJsonValidationErrors('weights');

        $admin->putJson('/api/v1/reports/scoring/weights', ['weights' => ['conversion' => 100] + $all])
            ->assertOk()->assertJsonPath('data.weights.conversion', 100);
        $this->assertEquals(2.5, $ada()['score'], 'conversion alone');

        $viewer = $this->userWithRole($t, 'Lead Manager', ['reports.scoring.view']);
        $this->signedInAs($viewer)->getJson('/api/v1/reports/scoring/weights')->assertOk()->assertJsonPath('data.weights.conversion', 100);
        $this->signedInAs($viewer)->putJson('/api/v1/reports/scoring/weights', ['weights' => ['conversion' => 100] + $all])->assertForbidden();
    }

    public function test_people_are_averaged_and_ranked_from_the_ai_start_day(): void
    {
        config(['app.timezone' => 'America/New_York', 'services.grok_cli.since' => '2026-10-13']);
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([
                ['a1', 'Ada', 8, '2026-10-13 14:00:00'], ['a2', 'Ada', 6, '2026-10-13 15:00:00'],
                ['a3', 'Ada', 7, '2026-10-14 14:00:00'],
                ['c1', 'Cy', 9, '2026-10-13 14:00:00'], ['c2', 'Cy', 9, '2026-10-13 15:00:00'],
                ['c3', 'Cy', 9, '2026-10-14 15:00:00'],
                ['b1', 'Bo', 10, '2026-10-13 14:00:00'],          // too few to rank
                ['old', 'Ada', 1, '2026-10-12 14:00:00'],         // before the AI start day
            ] as [$id, $who, $score, $at]) {
                CallReportTranscript::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id,
                    'transcript' => 'x', 'source' => 'whisper', 'answered_by' => $who, 'call_started_at' => $at]);
                CallReportScore::query()->create(['organization_id' => $t['org']->id, 'call_id' => $id, 'score' => $score,
                    'checks' => ['greeted' => $score >= 7], 'strength' => "good $id", 'improve' => "better $id"]);
            }

            foreach (['2026-10-13', '2026-10-14', '2026-10-15'] as $day) {
                CallReportPersonReview::query()->create(['person' => 'Cy', 'period' => 'daily', 'from' => $day, 'to' => $day,
                    'calls' => 2, 'avg_score' => 9, 'summary' => "Cy on $day", 'strengths' => ['a', 'b'], 'work_on' => ['c', 'd'], 'trend' => 'up']);
            }
        });

        $people = $this->signedInAs($this->userWithRole($t, 'Lead Manager', ['reports.scoring.view']))
            ->getJson('/api/v1/reports/scoring?from=2026-10-01&to=2026-10-14')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-10-13')
            ->json('data.people');

        $this->assertSame(['Bo', 'Cy', 'Ada'], array_column($people, 'person'));
        $this->assertSame([null, 1, 2], array_column($people, 'rank'));
        $this->assertSame([10, 9, 7], array_map(fn ($p) => (int) $p['avg_score'], $people));
        $this->assertSame(3, $people[2]['calls_scored']);
        $this->assertSame(67, $people[2]['checks']['greeted']);

        // The latest review ending inside the dates, not one after them.
        $this->assertSame('Cy on 2026-10-14', $people[1]['review_daily']['summary']);
        $this->assertNull($people[1]['review_weekly']);
        $this->assertNull($people[2]['review_daily']);

        $this->signedInAs($this->userWithRole($t, 'Tech', []))
            ->getJson('/api/v1/reports/scoring?from=2026-10-01&to=2026-10-14')
            ->assertForbidden();
    }
}
