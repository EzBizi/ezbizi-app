<?php

namespace Tests\Feature\Ai;

use App\Models\AutomationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: a weekly plain-English report, sent by an Automations rule. */
class AiWeeklySummaryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_last_weeks_figures_become_one_summary_event_signed_by_ai(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $t = $this->provisionTenant('A1-Group');
        $this->provisionTenant('Quiet Co');   // nothing last week: nothing raised
        $lastWeek = now('America/New_York')->startOfWeek()->subDays(3);
        foreach (['booked', 'booked', 'complaint'] as $label) {
            DB::table('call_report_labels')->insert([
                'id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'call_id' => uniqid(),
                'provider' => 'grok', 'label' => $label, 'created_at' => $lastWeek, 'updated_at' => $lastWeek,
            ]);
        }
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'headline' => 'Two calls booked, one complaint.',
            'summary' => 'Bookings held up. One complaint to call back.',
        ]]))]);

        $this->artisan('ezbizi:ai-weekly-summary')->assertSuccessful();

        Process::assertRanTimes(fn ($p) => str_contains(implode(' ', (array) $p->command), 'grok'), 1);
        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'reports.ai_summary_ready')->sole();
        $this->assertSame($t['org']->id, $event->organization_id);
        $this->assertStringContainsString('Written by EzBizi AI.', json_encode($event->payload));
    }
}
