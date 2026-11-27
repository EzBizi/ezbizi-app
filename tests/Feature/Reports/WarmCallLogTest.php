<?php

namespace Tests\Feature\Reports;

use App\Models\SocialConnection;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * CALLS-504 (2026-11-04): the report fetched every finished day nobody had
 * kept, met RingCentral's 429s and timed out. The warm keeps them first: every
 * account, in the organization's own days.
 */
class WarmCallLogTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_every_account_keeps_its_finished_days_in_the_organizations_zone(): void
    {
        // 01:30 UTC: still the 4th in New York, so the 4th is not finished yet.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-05 01:30', 'UTC'));
        Sleep::fake();
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $ids = $this->inTenant($tenant['org'], function () use ($tenant) {
            app(SettingsResolver::class)->set('timezone', $tenant['org'], 'America/New_York');

            return array_map(function (string $remote) use ($tenant) {
                $c = new SocialConnection;
                $c->forceFill(['organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                    'scope_id' => (string) $tenant['org']->id, 'provider' => 'ringcentral', 'remote_id' => $remote, 'display_name' => $remote, 'handle' => $remote]);
                $c->connect(['server' => "https://{$remote}.test", 'client_id' => $remote, 'client_secret' => 's', 'jwt' => 'j']);
                $c->save();

                return (string) $c->getKey();
            }, ['a1', 'ever']);
        });
        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => [], 'paging' => ['page' => 1]]),
            '*' => Http::response([]),
        ]);

        $this->artisan('ezbizi:warm-call-log', ['--days' => 2])->assertSuccessful();

        $kept = $this->inTenant($tenant['org'], fn () => collect($ids)->flatMap(function (string $id) {
            $log = app(RingCentralCallLog::class);
            $log->only($id);

            return collect(['2026-11-02', '2026-11-03', '2026-11-04'])
                ->mapWithKeys(fn (string $d) => ["{$id} {$d}" => $log->hasDay(CarbonImmutable::parse($d, 'America/New_York'))]);
        })->all());
        $this->assertSame([
            "{$ids[0]} 2026-11-02" => true, "{$ids[0]} 2026-11-03" => true, "{$ids[0]} 2026-11-04" => false,
            "{$ids[1]} 2026-11-02" => true, "{$ids[1]} 2026-11-03" => true, "{$ids[1]} 2026-11-04" => false,
        ], $kept);

        // Kept days are not read again.
        $before = count(Http::recorded());
        $this->artisan('ezbizi:warm-call-log', ['--days' => 2])->assertSuccessful();
        $this->assertSame($before, count(Http::recorded()));
    }
}
