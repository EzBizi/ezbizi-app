<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportPersonReview;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13: each employee's scores as cards, and a ranking. */
class ScoringReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

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

        $people = $this->signedInAs($this->userWithRole($t, 'Lead Manager', ['reports.experimental.view']))
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
