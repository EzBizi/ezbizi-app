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
        $this->assertSame(['ok', 'setup', 'broken', 'connected'], array_keys($app['counts']));
        $row = $this->find($data, $c->id);
        foreach (['id', 'provider', 'label', 'branch_id', 'branch_label', 'status', 'choices', 'used_by', 'last_checked_at', 'checks'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
        $this->assertSame(['key', 'label', 'state', 'reason', 'fix', 'checked_at', 'next_retry_at'], array_keys($row['checks'][0]));
        $this->assertSame('ok', $row['status']);
        $this->assertSame([['id' => $t['franchise']->id, 'label' => $t['franchise']->name]], $data['branches']);
    }

    public function test_counts_connected_is_only_rows_whose_status_is_connected(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, SocialProvider::GoogleAds->value, 'c1');
        $this->connection($t, SocialProvider::GoogleAds->value, 'c2');
        $this->connection($t, SocialProvider::GoogleAds->value, 'rev', ['status' => SocialConnection::STATUS_REVOKED]);
        $this->connection($t, SocialProvider::GoogleAds->value, 'brk',
            ['status' => SocialConnection::STATUS_BROKEN, 'status_reason' => 'Refused.']);
        // Paused in Settings at the branch: shown off, but its stored status
        // is still connected, and the Dashboard counts it, so this does too.
        $this->inTenant($t['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)
            ->set('leads.google_paused', $t['franchise'], true));
        $paused = $this->connection($t, SocialProvider::GoogleAds->value, 'pau', [],
            SocialConnection::SCOPE_FRANCHISE, $t['franchise']->id);

        $data = $this->overview($t);

        $this->assertSame('off', $this->find($data, $paused->id)['status']);
        $app = collect($data['apps'])->firstWhere('provider', SocialProvider::GoogleAds->value);
        $this->assertCount(5, $app['connections']);
        $this->assertSame(3, $app['counts']['connected']);
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
        $this->assertSame(['ok' => 0, 'setup' => 0, 'broken' => 1, 'connected' => 0], $ads['counts']);
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

    public function test_a_phone_line_shows_phones_keyed_health_checks(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $line = $this->inTenant($t['org'], function () use ($t) {
            $connection = \App\Models\PhoneConnection::factory()->create([
                'scope_type' => \App\Models\PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
            ]);

            // No E911 address on file: the `e911` check fails.
            return \App\Models\PhoneNumber::query()->create([
                'organization_id' => $t['org']->id,
                'connection_id' => $connection->id,
                'scope_type' => \App\Models\PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'twilio_sid' => 'PN'.str_repeat('c', 32),
                'e164' => '+15615550009',
                'label' => 'Main',
                'after_hours' => 'voicemail',
            ]);
        });

        $row = $this->find($this->overview($t), $line->id);

        $this->assertSame('phone', $row['provider']);
        $this->assertSame('broken', $row['status']);
        $this->assertSame(\App\Support\Phone\LineHealth::KEYS, array_column($row['checks'], 'key'));
        $pointed = collect($row['checks'])->firstWhere('key', 'e911');
        $this->assertSame('fail', $pointed['state']);
        $this->assertSame('E911 address', $pointed['label']);
        $this->assertSame('pass', collect($row['checks'])->firstWhere('key', 'pointed')['state']);
        $this->assertNotEmpty($pointed['reason']);
        // Not registered for A2P: waiting on Twilio's review, not on anybody here.
        $texting = collect($row['checks'])->firstWhere('key', 'texting');
        $this->assertSame('fail', $texting['state']);
        $this->assertSame('Twilio', $texting['waiting_on'] ?? null);
        $this->assertArrayNotHasKey('waiting_on', $pointed);
    }

    public function test_the_health_run_persists_workiz_health_as_checks(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connection($t, SocialProvider::Workiz->value, 'wz1', [], SocialConnection::SCOPE_FRANCHISE, $t['franchise']->id);
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->artisan('ezbizi:check-connection-health', ['--pause' => 0])->assertSuccessful();

        $checks = SocialConnection::withoutGlobalScopes()->find($c->id)->checks;
        $this->assertSame(ConnectionChecks::PASS, $checks['workiz_key']['state']);
        $this->assertSame(ConnectionChecks::FAIL, $checks['workiz_writes']['state'], 'a v1 key without its secret cannot write');
        $this->assertStringContainsString('v1 secret', $checks['workiz_writes']['reason']);

        $row = $this->find($this->overview($t), $c->id);
        $this->assertSame('broken', $row['status']);
        $this->assertSame('EzBizi can write to Workiz', collect($row['checks'])->firstWhere('key', 'workiz_writes')['label']);

        // Never watched yet: the sync step fails and offers the recheck.
        $sync = collect($row['checks'])->firstWhere('key', 'workiz_sync');
        $this->assertSame('Workiz sync', $sync['label']);
        $this->assertSame('fail', $sync['state']);
        $this->assertEquals(['action' => 'retry', 'label' => 'Check again'], $sync['fix']);
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
