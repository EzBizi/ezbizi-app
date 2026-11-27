<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Reports\Texts\NewClientConversion;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The staff bot's "Daily Conversion Updates (new clients only)". */
class NewClientConversionTest extends TestCase
{
    use BuildsTenants {
        provisionTenant as private tenantWithoutQueues;
    }
    use RefreshDatabase;

    /** A tenant with A-1's two queue names set, as Settings holds them (no names in code). */
    protected function provisionTenant(string $name = 'Alpha', ?string $email = null): array
    {
        $t = $this->tenantWithoutQueues($name, $email);
        $this->inTenant($t['org'], function () use ($t) {
            app(SettingsResolver::class)->set('leads.new_client_queue', $t['org'], 'A1 - New Client');
            app(SettingsResolver::class)->set('leads.existing_client_queue', $t['org'], 'A1 - Existing Client');
        });

        return $t;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_one_days_new_client_calls_booked_and_rate_per_dispatcher(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 07:00', 'America/New_York'));
        config(['app.timezone' => 'America/New_York',
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);
        $t = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        config(['experimental_reports.ringcentral.company_id' => $t['company']->id]);

        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id, 'provider' => SocialProvider::Workiz->value,
                'remote_id' => $t['franchise']->id, 'display_name' => 'Texas']);
            $c->connect(['refresh_token' => 'x', 'api_token' => 'x']);
            $c->save();
            // Another company's line: its calls await review (Analorena, 2026-11-23).
            $ace = Company::create(['organization_id' => $t['org']->id, 'name' => 'Ace Fireplace Services']);
            app(SettingsResolver::class)->set('leads.line_numbers', $t['org'], ['+15550008888' => 'company:'.$ace->id]);
            foreach ([['JOB-1', '2145550001', '2026-10-06 10:00:00'], ['JOB-2', '2145550003', '2026-09-29 10:00:00']] as [$id, $phone, $at]) {
                WorkizJob::query()->create(['franchise_id' => $t['franchise']->id, 'connection_id' => $c->id,
                    'remote_id' => $id, 'booked_at' => $at, 'synced_at' => now(),
                    'payload' => ['UUID' => $id, 'Phone' => $phone, 'CreatedDate' => $at, 'Status' => 'Submitted']]);
            }
        });

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/phone-number*' => Http::response(['records' => []]),
            '*/call-log*' => Http::response(['records' => [
                $this->rc('booked', '+12145550001', '14:00', 'Trey Brannan'),
                $this->rc('lost', '+12145550002', '15:00', 'Trey Brannan'),
                $this->rc('had-job', '+12145550003', '16:00', 'Trey Brannan'),
                $this->rc('missed', '+12145550004', '17:00', null),
                ['to' => ['phoneNumber' => '+15550008888']] + $this->rc('ace', '+12145550005', '18:00', 'Trey Brannan'),
            ], 'paging' => ['page' => 1]]),
        ]);

        $text = NewClientConversion::line($t['org'], CarbonImmutable::parse('2026-10-06', 'America/New_York'));

        $this->assertSame(implode("\n", [
            '📞 *New-client conversion · Tue, Oct 6*',
            '',
            '📲 5 new-client calls',
            '🙋 4 answered',
            '✅ *1* booked',
            '📈 Conversion: *50%*',
            '🔁 1 already had a job (not counted)',
            "⏳ 1 awaiting review: another company's calls (not counted)",
            '',
            '👥 *By dispatcher*',
            '• Trey Brannan: 1/2 booked · *50%*',
        ]), $text);
    }

    private function rc(string $id, string $from, string $utc, ?string $answeredBy): array
    {
        $legs = [
            ['legType' => 'Accept', 'result' => $answeredBy ? 'Accepted' : 'Missed', 'to' => ['name' => 'Main - IVR']],
            ['legType' => 'Accept', 'result' => $answeredBy ? 'Accepted' : 'Missed', 'to' => ['name' => 'A1 - New Client']],
        ];
        if ($answeredBy) {
            $legs[] = ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => $answeredBy], 'to' => ['extensionNumber' => '748'], 'duration' => 60];
        }

        return ['id' => $id, 'direction' => 'Inbound', 'result' => $answeredBy ? 'Accepted' : 'Missed',
            'startTime' => "2026-10-06T{$utc}:00Z", 'from' => ['phoneNumber' => $from, 'name' => 'CALLER'],
            'to' => ['phoneNumber' => '+15550009999'], 'legs' => $legs];
    }
}
