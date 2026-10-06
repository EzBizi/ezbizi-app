<?php

namespace Tests\Feature\Ads;

use App\Models\AdsAccountState;
use App\Models\AutomationEvent;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Row D of REPLACING-RODRIGO-BOT: the 06:30 Daily Account Review. */
class AdsDailyReviewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
    }

    private function account(string $name, string $campaign = 'ELIGIBLE', array $problems = [], string $status = 'ENABLED'): AdsAccountState
    {
        return $this->inTenant($this->t['org'], function () use ($name, $campaign, $problems, $status) {
            $c = SocialConnection::create([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => (string) random_int(1000000000, 9999999999),
                'display_name' => $name,
            ]);

            return AdsAccountState::create([
                'organization_id' => $this->t['org']->id,
                'connection_id' => $c->id,
                'campaigns' => [['id' => '1', 'name' => 'LSA', 'status' => $status, 'primary_status' => $campaign]],
                'certification_problems' => array_map(fn ($p) => ['code' => $p, 'text' => "{$p}."], $problems),
                'certification_problem_count' => count($problems),
            ]);
        });
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'ads.daily_review')->orderBy('id')->get()->all();
    }

    public function test_monday_snapshot_then_only_changes(): void
    {
        $this->account('Atlanta');
        $plano = $this->account('Plano', problems: ['Insurance expired']);
        $this->account('Denver', 'NOT_ELIGIBLE');
        $this->account('Boston', 'PAUSED', status: 'PAUSED');

        Carbon::setTestNow('2026-10-05T10:30:00Z'); // Monday
        $this->artisan('ezbizi:ads-daily-review')->assertSuccessful();
        $summary = $this->raised()[0]->payload['summary'];
        $this->assertStringContainsString('2 issues / 4 accounts', $summary);
        $this->assertStringContainsString("*Denver*: (not eligible)", $summary);
        $this->assertStringContainsString('*Plano*: Insurance expired', $summary);
        $this->assertStringContainsString('*PAUSED* (1): Boston', $summary);
        $this->assertStringContainsString('*HEALTHY* 🟢 (1): Atlanta', $summary);

        Carbon::setTestNow('2026-10-06T10:30:00Z'); // Tuesday, nothing moved
        $this->artisan('ezbizi:ads-daily-review')->assertSuccessful();
        $this->assertCount(1, $this->raised(), 'no change, no message');

        $this->inTenant($this->t['org'], fn () => $plano->forceFill(['certification_problems' => [], 'certification_problem_count' => 0])->save());
        Carbon::setTestNow('2026-10-07T10:30:00Z');
        $this->artisan('ezbizi:ads-daily-review')->assertSuccessful();
        $changes = $this->raised()[1]->payload['summary'];
        $this->assertStringContainsString('1 change', $changes);
        $this->assertStringContainsString("*RESOLVED* ✅ (1):\n*Plano*", $changes);
    }
}
