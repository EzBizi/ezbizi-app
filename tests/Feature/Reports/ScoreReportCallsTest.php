<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12, AI item 8: a coaching read of each answered call. */
class ScoreReportCallsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CHECKS = ['greeted' => true, 'got_name' => true, 'got_address' => false, 'found_need' => true,
        'offered_time' => false, 'gave_price' => true, 'asked_for_booking' => false];

    private function answeredCall(array $t, string $id, ?string $labelled): void
    {
        $this->inTenant($t['org'], fn () => CallReportTranscript::query()->create([
            'organization_id' => $t['org']->id, 'call_id' => $id, 'transcript' => 'x',
            'transcript_labelled' => $labelled, 'source' => 'whisper',
        ]));
    }

    public function test_nothing_is_scored_while_switched_off(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'experimental_reports.scoring' => false]);
        $this->answeredCall($this->provisionTenant('A1-Group'), 'rc-1', "Dispatcher: A1 Chimney.\n\nCustomer: Price?");
        Process::fake();

        $this->artisan('ezbizi:score-report-calls')->assertSuccessful();

        Process::assertNothingRan();
        $this->assertSame(0, CallReportScore::withoutGlobalScopes()->count());
    }

    public function test_answered_calls_are_scored_once_and_unanswered_ones_never_asked(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok', 'experimental_reports.scoring' => true]);
        $t = $this->provisionTenant('A1-Group');
        $this->answeredCall($t, 'rc-answered', "Dispatcher: A1 Chimney.\n\nCustomer: How much is a sweep?");
        $this->answeredCall($t, 'rc-menu-only', 'IVR menu: Press 1.');
        $this->answeredCall($t, 'rc-not-split', null);
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'scorable' => true, 'score' => 6, 'checks' => self::CHECKS,
            'strength' => 'Gave the price clearly.', 'improve' => 'Offer the Thursday slot before hanging up.',
        ]]))]);

        $this->artisan('ezbizi:score-report-calls')->assertSuccessful();
        $this->artisan('ezbizi:score-report-calls')->assertSuccessful();

        Process::assertRanTimes(fn () => true, 1);
        $row = CallReportScore::withoutGlobalScopes()->sole();
        $this->assertSame(['rc-answered', 6, 'Offer the Thursday slot before hanging up.'], [$row->call_id, $row->score, $row->improve]);
        $this->assertFalse($row->checks['offered_time']);
    }
}
