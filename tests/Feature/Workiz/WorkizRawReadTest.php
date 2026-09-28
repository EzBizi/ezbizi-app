<?php

namespace Tests\Feature\Workiz;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The importer's reader, which is deliberately not the report's.
 */
class WorkizRawReadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $tenant, Franchise $branch, string $token): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $branch, $token) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $branch->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $branch->id,
                'display_name' => $branch->name,
            ]);
            $c->connect(['api_token' => $token, 'refresh_token' => $token]);
            $c->save();

            return $c;
        });
    }

    public function test_it_returns_whole_payloads_not_the_reports_five_fields(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [$payload]])]);

        $connection = $this->connect($tenant, $tenant['franchise'], 'api_good');

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertNull($out['failed']);
        $this->assertCount(1, $out['jobs']);

        // A custom field proves this is the whole payload and not shape()'s
        // five columns — shape() would have dropped it without a trace.
        $this->assertSame('Gas System', $out['jobs'][0]['system_type']);
        $this->assertSame('Upstate New York ', $out['jobs'][0]['ServiceArea']);
        $this->assertSame('1 Example Street', $out['jobs'][0]['Address']);
    }

    /**
     * A refusal carries a status and never a URL. The token is in the PATH, so
     * anything cURL or Guzzle composed from the request carries the credential.
     */
    public function test_a_refusal_carries_a_status_and_never_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        Http::fake(['api.workiz.com/*' => Http::response([], 403)]);

        $connection = $this->connect($tenant, $tenant['franchise'], 'api_secret_token');

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertSame([], $out['jobs']);
        $this->assertStringContainsString('403', (string) $out['failed']);
        $this->assertStringNotContainsString('api_secret_token', (string) $out['failed']);
        $this->assertStringNotContainsString('workiz.com', (string) $out['failed']);
    }

    /**
     * WORKIZ'S QUOTA REFUSAL IS A 429 AND IT IS NOT HYPOTHETICAL.
     *
     * A-1's own sync collected 26 of them on 2026-09-23 at a two-minute
     * cadence, with the body `{"error":true,"code":429,"msg":"Account reached
     * Api Quotas..."}`. A per-minute sweep must record it as this branch's
     * failure and leave the others alone, not raise.
     */
    public function test_a_quota_refusal_is_recorded_rather_than_thrown(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        Http::fake(['api.workiz.com/*' => Http::response(
            ['error' => true, 'code' => 429, 'msg' => 'Account reached Api Quotas - Please try again later'], 429,
        )]);

        $connection = $this->connect($tenant, $tenant['franchise'], 'api_good');

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertSame([], $out['jobs']);
        $this->assertStringContainsString('429', (string) $out['failed']);
    }

    /**
     * A full page means there is another one. Workiz caps a page at 100 and
     * refuses more, so the only way to know the end is a short page.
     */
    public function test_a_full_page_is_followed_by_the_next_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');

        $full = [];
        for ($i = 0; $i < WorkizJobs::PAGE; $i++) {
            $full[] = array_merge($payload, ['UUID' => 'JOB'.$i]);
        }

        Http::fake(['api.workiz.com/*' => Http::sequence()
            ->push(['flag' => true, 'data' => $full])
            ->push(['flag' => true, 'data' => [array_merge($payload, ['UUID' => 'LAST'])]]),
        ]);

        $connection = $this->connect($tenant, $tenant['franchise'], 'api_good');

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertCount(WorkizJobs::PAGE + 1, $out['jobs']);
        $this->assertSame('LAST', end($out['jobs'])['UUID']);
    }

    /**
     * `offset` is Workiz's PAGE NUMBER, not a row count. Measured 2026-09-28 on
     * Connecticut (192 upcoming jobs): offset=1 returned the next 99, offset=100
     * returned none. And page 0 is the FARTHEST 100 by date, so a sweep that
     * asked for "row 100" read one page and never saw the next nine days.
     *
     * The test above could not catch it: its fake answered in sequence,
     * whatever the request asked for. This one answers by the offset.
     */
    public function test_the_second_page_is_asked_for_by_page_number(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');

        $farthest = [];
        for ($i = 0; $i < WorkizJobs::PAGE; $i++) {
            $farthest[] = array_merge($payload, ['UUID' => 'FAR'.$i]);
        }

        Http::fake(function (Request $request) use ($farthest, $payload) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['flag' => true, 'data' => match ((string) ($query['offset'] ?? '')) {
                '0' => $farthest,
                '1' => [array_merge($payload, ['UUID' => 'TOMORROW'])],
                default => [],
            }]);
        });

        $connection = $this->connect($tenant, $tenant['franchise'], 'api_good');

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertCount(WorkizJobs::PAGE + 1, $out['jobs']);
        $this->assertSame('TOMORROW', end($out['jobs'])['UUID']);
    }

    public function test_a_connection_with_no_token_is_named_rather_than_skipped(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $connection = $this->inTenant($tenant['org'], function () use ($tenant) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $tenant['franchise']->id,
                'display_name' => 'Tokenless',
            ]);
            $c->save();

            return $c;
        });

        $out = app(WorkizJobs::class)->raw($connection);

        $this->assertSame([], $out['jobs']);
        $this->assertNotNull($out['failed']);
    }

    /**
     * Every request asks for a FULL page, and the result is trimmed. If Workiz
     * starts page N at N x records, a smaller `records` on the last request
     * would move where it starts -- the Reports session's point, fixing the
     * same loop in all().
     */
    public function test_a_limit_between_pages_still_asks_for_full_pages(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        $page = fn (string $prefix) => array_map(fn ($i) => array_merge($payload, ['UUID' => $prefix.$i]), range(1, WorkizJobs::PAGE));

        Http::fake(function (Request $request) use ($page) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['flag' => true, 'data' => match ((string) ($query['offset'] ?? '')) {
                '0' => $page('A'),
                '1' => $page('B'),
                default => [],
            }]);
        });

        $out = app(WorkizJobs::class)->raw($this->connect($tenant, $tenant['franchise'], 'api_good'), 150);

        $this->assertCount(150, $out['jobs']);
        Http::assertNotSent(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (string) ($query['records'] ?? '') !== (string) WorkizJobs::PAGE;
        });
    }
}
