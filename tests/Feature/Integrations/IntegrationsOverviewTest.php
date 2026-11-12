<?php

namespace Tests\Feature\Integrations;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\ConnectionChecks;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class IntegrationsOverviewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t, string $provider, string $remoteId, array $extra = [], string $scopeType = SocialConnection::SCOPE_ORGANIZATION, ?string $scopeId = null): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId, $extra, $scopeType, $scopeId) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => $scopeType,
                'scope_id' => $scopeId ?? $t['org']->id, 'provider' => $provider, 'remote_id' => $remoteId, 'display_name' => 'Acct '.$remoteId]);
            $c->connect(['refresh_token' => 'r', 'access_token' => 'tok', 'api_token' => 'tok']);
            $c->forceFill($extra)->save();

            return $c;
        });
    }

    private function overview(array $t): array
    {
        return $this->signedInAs($t['admin'])->getJson('/api/v1/integrations/overview')->assertOk()->json('data');
    }

    private function find(array $data, string $id): array
    {
        foreach ($data['apps'] as $app) {
            foreach ($app['connections'] as $c) {
                if ($c['id'] === $id) {
                    return $c;
                }
            }
        }
        $this->fail("connection $id not in payload");
    }

    public function test_payload_shape(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::Airtable->value, 'base1', ['last_checked_at' => now()]);

        $data = $this->overview($t);

        $this->assertSame(['summary', 'apps', 'branches'], array_keys($data));
        $this->assertSame(['broken', 'setup'], array_keys($data['summary']));
        $app = collect($data['apps'])->firstWhere('provider', 'airtable');
        $this->assertSame(['provider', 'label', 'counts', 'last_checked_at', 'connections'], array_keys($app));
        $this->assertSame(['ok', 'setup', 'broken'], array_keys($app['counts']));
        $row = $this->find($data, $c->id);
        foreach (['id', 'provider', 'label', 'branch_id', 'branch_label', 'status', 'choices', 'used_by', 'last_checked_at', 'checks'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
        $this->assertSame(['key', 'label', 'state', 'reason', 'fix', 'checked_at', 'next_retry_at'], array_keys($row['checks'][0]));
        $this->assertSame('ok', $row['status']);
        $this->assertSame([['id' => $t['franchise']->id, 'label' => $t['franchise']->name]], $data['branches']);
    }

    public function test_summary_math_and_status_precedence(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ok = $this->connection($t, SocialProvider::Airtable->value, 'ok', ['last_checked_at' => now()]);
        $broken = $this->connection($t, SocialProvider::Airtable->value, 'broken',
            ['status' => SocialConnection::STATUS_BROKEN, 'status_reason' => 'Refused.']);
        // Broken AND no branch: broken wins over setup.
        $both = $this->connection($t, SocialProvider::GoogleAds->value, 'both',
            ['status' => SocialConnection::STATUS_BROKEN, 'status_reason' => 'Refused.']);
        $setup = $this->connection($t, SocialProvider::Thumbtack->value, 'setup');
        $off = $this->connection($t, SocialProvider::GoogleAds->value, 'off', ['status' => SocialConnection::STATUS_REVOKED]);

        $data = $this->overview($t);

        $this->assertSame('ok', $this->find($data, $ok->id)['status']);
        $this->assertSame('broken', $this->find($data, $broken->id)['status']);
        $this->assertSame('reconnect', $this->find($data, $broken->id)['checks'][0]['fix']['action']);
        $this->assertSame('broken', $this->find($data, $both->id)['status']);
        $this->assertSame('setup', $this->find($data, $setup->id)['status']);
        $this->assertSame('off', $this->find($data, $off->id)['status']);
        $this->assertSame(['broken' => 2, 'setup' => 1], $data['summary'], 'off is never attention');
        $ads = collect($data['apps'])->firstWhere('provider', 'google_ads');
        $this->assertSame(['ok' => 0, 'setup' => 0, 'broken' => 1], $ads['counts']);
    }

    public function test_missing_branch_is_setup_with_choose_branch_and_a_set_one_is_ok(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $missing = $this->connection($t, SocialProvider::FacebookPage->value, 'p1', ['last_checked_at' => now()]);
        $set = $this->connection($t, SocialProvider::WhatsApp->value, 'w1',
            ['last_checked_at' => now(), 'leads_franchise_id' => $t['franchise']->id]);

        $data = $this->overview($t);

        $row = $this->find($data, $missing->id);
        $this->assertSame('setup', $row['status']);
        $this->assertSame('choose_branch', collect($row['checks'])->firstWhere('key', 'branch')['fix']['action']);
        $row = $this->find($data, $set->id);
        $this->assertSame('ok', $row['status']);
        $this->assertSame($t['franchise']->id, $row['branch_id']);
        $this->assertSame($t['franchise']->name, $row['branch_label']);
    }

    public function test_a_sibling_company_cannot_see_the_other_companys_connections(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->inTenant($t['org'], fn () => Franchise::create([
            'company_id' => Company::create(['organization_id' => $t['org']->id, 'name' => 'Everdoors'])->id,
            'name' => 'Everdoors F1',
        ]));
        $mine = $this->connection($t, SocialProvider::Airtable->value, 'mine', [], SocialConnection::SCOPE_FRANCHISE, $t['franchise']->id);
        $theirs = $this->connection($t, SocialProvider::Airtable->value, 'theirs', [], SocialConnection::SCOPE_FRANCHISE, $sibling->id);
        $user = $this->userWithRole($t, 'Branch', ['marketing.view', 'marketing.edit', 'reports.build'], null, $t['franchise']);

        $data = $this->signedInAs($user)->getJson('/api/v1/integrations/overview')->assertOk()->json('data');
        $ids = collect($data['apps'])->flatMap(fn ($a) => collect($a['connections'])->pluck('id'))->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
        $this->signedInAs($user)->postJson("/api/v1/integrations/{$theirs->id}/recheck")->assertNotFound();
    }

    public function test_the_health_run_persists_per_check_results(): void
    {
        Queue::fake();
        config(['services.meta.client_id' => 'app1']);
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, SocialProvider::FacebookPage->value, 'page1');
        $dead = $this->connection($t, SocialProvider::Airtable->value, 'dead');
        Http::fake(fn ($r) => match (true) {
            str_contains($r->url(), 'subscribed_apps') => Http::response(['data' => [['id' => 'app1', 'subscribed_fields' => ['messages']]]]),
            str_contains($r->url(), 'airtable') => Http::response([], 401),
            default => Http::response(['id' => 'x']),
        });

        $this->artisan('ezbizi:check-connection-health', ['--pause' => 0])->assertSuccessful();

        $checks = SocialConnection::withoutGlobalScopes()->find($page->id)->checks;
        $this->assertSame(ConnectionChecks::PASS, $checks['sign_in']['state']);
        $this->assertSame(ConnectionChecks::PASS, $checks['messages']['state']);
        $this->assertNotNull($checks['sign_in']['checked_at']);
        $this->assertNotNull($checks['sign_in']['next_retry_at']);
        $this->assertSame(ConnectionChecks::FAIL, SocialConnection::withoutGlobalScopes()->find($dead->id)->checks['sign_in']['state']);

        $row = $this->find($this->overview($t), $dead->id);
        $this->assertSame('broken', $row['status']);
        $this->assertSame('fail', collect($row['checks'])->firstWhere('key', 'sign_in')['state']);
    }

    public function test_recheck_returns_the_updated_connection_and_is_throttled(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::Airtable->value, 'base1');
        Http::fake(['*' => Http::response(['id' => 'x'])]);

        $row = $this->signedInAs($t['admin'])->postJson("/api/v1/integrations/{$c->id}/recheck")->assertOk()->json('data');

        $this->assertSame($c->id, $row['id']);
        $this->assertSame('ok', $row['status']);
        $this->assertSame('pass', collect($row['checks'])->firstWhere('key', 'sign_in')['state']);
        $this->assertSame($row, $this->find($this->overview($t), $c->id), 'same shape as the overview');

        $this->signedInAs($t['admin'])->postJson("/api/v1/integrations/{$c->id}/recheck")->assertStatus(429);
    }
}
