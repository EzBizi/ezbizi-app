<?php

namespace Tests\Feature\Workiz;

use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Who booked each job (Reports, 2026-10-06): v2 never says, v1 did, and the
 * per-dispatcher report credits bookings by it.
 */
class FillWorkizCreatorsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: SocialConnection} */
    private function branch(bool $keptV1 = true): array
    {
        $tenant = $this->provisionTenant('Alpha');

        $connection = $this->inTenant($tenant['org'], function () use ($tenant, $keptV1) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $tenant['franchise']->id,
                'display_name' => 'Connecticut',
            ]);
            $c->connect(['api_token' => 'v2key_CT', 'refresh_token' => 'v2key_CT', 'api_version' => 'v2']
                + ($keptV1 ? ['v1' => ['api_token' => 'api_CT', 'auth_secret' => 'sec_CT']] : []));
            $c->save();

            return $c;
        });

        return [$tenant, $connection];
    }

    /** A job as v2's list lands it today: no creator. */
    private function landV2(array $tenant, SocialConnection $connection, string $uuid = 'NYCZ7W'): void
    {
        $job = array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['uuid' => $uuid, 'id' => 'JOB-'.$uuid.'0000', 'createdAt' => now()->format('Y-m-d H:i:s')]);
        $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, WorkizV2::job($job)));
    }

    private function row(array $tenant, string $uuid = 'NYCZ7W'): WorkizJob
    {
        return $this->inTenant($tenant['org'], fn () => WorkizJob::query()->where('remote_id', $uuid)->sole());
    }

    public function test_a_v2_landing_keeps_who_booked_the_job(): void
    {
        [$tenant, $connection] = $this->branch();
        $v1 = ['UUID' => 'NYCZ7W', 'SerialId' => 11008, 'Status' => 'Submitted', 'JobDateTime' => '2026-10-07 08:00:00', 'CreatedDate' => now()->format('Y-m-d H:i:s'), 'CreatedBy' => 'Ana Ruiz'];
        $this->inTenant($tenant['org'], fn () => WorkizJob::land($connection, $v1));

        $this->landV2($tenant, $connection);

        $this->assertSame(['Ana Ruiz', 'Ana Ruiz'], [$this->row($tenant)->payload['CreatedBy'], $this->row($tenant)->created_by]);
    }

    public function test_a_new_v2_job_is_given_its_booker_by_v1_and_asked_about_once(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->landV2($tenant, $connection);
        Http::fake(['api.workiz.com/api/v1/*/job/get/NYCZ7W/' => Http::response(['flag' => true, 'data' => [['UUID' => 'NYCZ7W', 'CreatedBy' => 'Ana Ruiz']]])]);

        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();
        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();

        $this->assertSame(['Ana Ruiz', 'Ana Ruiz'], [$this->row($tenant)->payload['CreatedBy'], $this->row($tenant)->created_by]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/api_CT/job/get/NYCZ7W/'));
    }

    /** v1 names nobody (204): recorded as nobody, and not asked about every five minutes for ever. */
    public function test_a_job_v1_names_nobody_for_is_not_asked_about_again(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->landV2($tenant, $connection);
        Http::fake(['api.workiz.com/*' => Http::response(null, 204)]);

        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();
        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();

        $this->assertSame(['', null], [$this->row($tenant)->payload['CreatedBy'], $this->row($tenant)->created_by]);
        Http::assertSentCount(1);
    }

    /** The backfill reads v1's list and fills only the rows that have no creator. */
    public function test_the_backfill_fills_blank_rows_from_v1s_list(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->landV2($tenant, $connection, 'AAAAAA');
        $this->landV2($tenant, $connection, 'BBBBBB');
        $this->inTenant($tenant['org'], fn () => WorkizJob::query()->where('remote_id', 'BBBBBB')->update(['created_by' => 'Kept Person']));
        Http::fake(['api.workiz.com/api/v1/*/job/all/*' => Http::response(['flag' => true, 'data' => [
            ['UUID' => 'AAAAAA', 'CreatedBy' => 'Ana Ruiz'],
            ['UUID' => 'BBBBBB', 'CreatedBy' => 'Someone Else'],
        ]])]);

        $this->artisan('ezbizi:workiz-fill-creators', ['--since' => '2023-01-01', '--pause' => 0])->assertSuccessful();

        $this->assertSame('Ana Ruiz', $this->row($tenant, 'AAAAAA')->created_by);
        $this->assertSame('Kept Person', $this->row($tenant, 'BBBBBB')->created_by);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start_date=2023-01-01'));
    }

    public function test_a_branch_with_no_v1_pair_is_not_asked(): void
    {
        [$tenant, $connection] = $this->branch(keptV1: false);
        $this->landV2($tenant, $connection);
        Http::fake();

        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** The limit is the key's: a refusal ends that branch's run. */
    public function test_a_refusal_stops_the_branch(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->landV2($tenant, $connection, 'AAAAAA');
        $this->landV2($tenant, $connection, 'BBBBBB');
        Http::fake(['api.workiz.com/*' => Http::response(['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas'], 429)]);

        $this->artisan('ezbizi:workiz-fill-creators')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNull($this->row($tenant, 'AAAAAA')->created_by);
    }
}
