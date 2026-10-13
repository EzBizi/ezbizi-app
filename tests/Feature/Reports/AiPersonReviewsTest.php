<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportPersonReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-13: a daily and weekly AI read of each employee, for the Scoring tab. */
class AiPersonReviewsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function scored(array $t, string $person, int $score, string $at): void
    {
        $id = uniqid('rc');
        DB::table('call_report_transcripts')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => 'x',
            'source' => 'whisper', 'answered_by' => $person, 'call_started_at' => $at, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('call_report_scores')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'call_id' => $id, 'score' => $score,
            'checks' => json_encode(['offered_time' => false, 'greeted' => true]), 'strength' => 'Friendly.',
            'improve' => 'Offer a day.', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_review_per_person_with_enough_calls_and_an_arithmetic_trend(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'experimental_reports.scoring' => true]);
        $t = $this->provisionTenant('A1-Group');
        foreach ([8, 8, 9] as $s) {
            $this->scored($t, 'Natalie Escobar', $s, '2026-10-12 15:00:00');   // the day reviewed
        }
        foreach ([5, 5, 5] as $s) {
            $this->scored($t, 'Natalie Escobar', $s, '2026-10-11 15:00:00');   // the day before
        }
        $this->scored($t, 'Eli Deblinger', 7, '2026-10-12 15:00:00');          // too few: no review
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'summary' => 'Warm and quick.', 'strengths' => ['Greets well', 'Clear on price', 'extra'], 'work_on' => ['Offer a day'],
        ]]))]);

        $this->artisan('ezbizi:ai-person-reviews', ['period' => 'daily', '--date' => '2026-10-12'])->assertSuccessful();

        $review = CallReportPersonReview::withoutGlobalScopes()->sole();
        $this->assertSame(['Natalie Escobar', 3, 'up'], [$review->person, $review->calls, $review->trend]);
        $this->assertSame(['Greets well', 'Clear on price'], $review->strengths);
        $this->assertEqualsWithDelta(8.33, $review->avg_score, 0.01);
    }

    public function test_nothing_runs_while_scoring_is_off(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'experimental_reports.scoring' => false]);
        Process::fake();

        $this->artisan('ezbizi:ai-person-reviews', ['period' => 'weekly'])->assertSuccessful();

        Process::assertNothingRan();
    }
}
