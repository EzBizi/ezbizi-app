<?php

namespace Tests\Feature\Reports;

use App\Models\Company;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An organization's own RingCentral account on Settings › Integrations
 * (Jose, 2026-10-15), and the call log reading it before the env credential —
 * which belongs to A1-Group alone.
 */
class RingCentralConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TOKEN = 'https://platform.ringcentral.com/restapi/oauth/token';

    private const ACCOUNT = 'https://platform.ringcentral.com/restapi/v1.0/account/~';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'env-id',
            'experimental_reports.ringcentral.client_secret' => 'env-secret',
            'experimental_reports.ringcentral.jwt' => 'env-jwt',
        ]);
    }

    // ── connecting ───────────────────────────────────────────────

    public function test_connecting_verifies_the_jwt_and_stores_the_account(): void
    {
        $t = $this->provisionTenant('Alpha');
        Http::fake([
            self::TOKEN => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::ACCOUNT => Http::response(['id' => '401234', 'mainNumber' => '+15550100']),
            self::ACCOUNT.'/business-address' => Http::response(['company' => 'Alpha Chimney']),
        ]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/ringcentral', ['client_id' => 'cid', 'client_secret' => 'sec', 'jwt' => 'jwt-1'])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Alpha Chimney')
            ->assertJsonPath('data.provider', 'ringcentral');

        Http::assertSent(fn (Request $r) => $r->url() === self::TOKEN
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:sec'))
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $r['assertion'] === 'jwt-1');

        $row = $this->inTenant($t['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::RingCentral->value)->sole());

        $this->assertSame('401234', $row->remote_id);
        $this->assertSame(SocialConnection::SCOPE_ORGANIZATION, $row->scope_type);
        $this->assertSame('jwt-1', $row->credentials['jwt']);
        $this->assertSame('cid', $row->credentials['client_id']);

        // Encrypted at rest, like the others.
        $raw = (string) \DB::table('social_connections')->where('id', $row->id)->value('credentials');
        $this->assertStringNotContainsString('jwt-1', $raw);
    }

    public function test_bad_credentials_are_refused_with_a_sentence_and_write_no_row(): void
    {
        $t = $this->provisionTenant('Alpha');
        Http::fake([self::TOKEN => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/ringcentral', ['client_id' => 'cid', 'client_secret' => 'sec', 'jwt' => 'bad'])
            ->assertStatus(422)
            ->assertJsonPath('errors.jwt.0', 'RingCentral did not accept that JWT for this app. Create a new JWT credential and paste it.');

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::query()
            ->where('provider', SocialProvider::RingCentral->value)->count()));
    }

    public function test_connecting_needs_the_reports_permission(): void
    {
        $this->assertSame('reports.build', SocialProvider::RingCentral->connectPermission());
    }

    // ── the call log ─────────────────────────────────────────────

    public function test_the_call_log_uses_the_connection_over_env(): void
    {
        $t = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connect($t, SocialConnection::SCOPE_ORGANIZATION, $t['org']->id);
        $this->fakeCallLog();

        $this->inTenant($t['org'], fn () => app(RingCentralCallLog::class)->forDate('x', 'y'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/restapi/oauth/token')
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('conn-id:conn-secret')));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ringcentral.test'));
    }

    public function test_a1_group_falls_back_to_env_without_a_connection(): void
    {
        $t = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->fakeCallLog();

        $this->inTenant($t['org'], fn () => app(RingCentralCallLog::class)->forDate('x', 'y'));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://platform.ringcentral.test/restapi/oauth/token'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('env-id:env-secret')));
    }

    public function test_another_organization_without_a_connection_does_not_read_env(): void
    {
        $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $other = $this->provisionTenant('Beta');
        $this->fakeCallLog();

        $this->inTenant($other['org'], function () {
            $log = app(RingCentralCallLog::class);
            $this->assertFalse($log->configured());

            try {
                $log->forDate('x', 'y');
                $this->fail('read a call log with no connection');
            } catch (RuntimeException $e) {
                $this->assertSame(RingCentralCallLog::NOT_CONNECTED, $e->getMessage());
            }
        });

        Http::assertNothingSent();
    }

    public function test_a_sibling_company_cannot_see_or_use_the_connection(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->connect($t, SocialConnection::SCOPE_COMPANY, $t['company']->id);

        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id, 'name' => 'Sibling Co',
        ]));
        $this->inTenant($t['org'], fn () => $t['admin']->forceFill([
            'preferences' => ['navigation.scope' => 'company:'.$sibling->getKey()],
        ])->save());

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections?include=data_sources')
            ->assertOk()
            ->assertJsonMissing(['provider' => 'ringcentral']);

        $this->fakeCallLog();
        $this->inTenant($t['org'], function () {
            $this->assertFalse(app(RingCentralCallLog::class)->configured());
        });
        Http::assertNothingSent();
    }

    // ── Integrations ─────────────────────────────────────────────

    public function test_integrations_lists_it_and_its_connection(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/providers?include=data_sources')
            ->assertOk()
            ->assertJsonFragment([
                'value' => 'ringcentral', 'label' => 'RingCentral', 'connects_by' => 'api_key',
                'permission' => 'reports.build', 'usable' => true,
            ]);

        // Not in the Marketing composer's list.
        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/providers')
            ->assertJsonMissing(['value' => 'ringcentral']);

        $this->connect($t, SocialConnection::SCOPE_ORGANIZATION, $t['org']->id);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections?include=data_sources')
            ->assertOk()
            ->assertJsonFragment(['provider' => 'ringcentral', 'status' => 'connected']);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function connect(array $t, string $scopeType, string $scopeId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => SocialProvider::RingCentral->value,
                'remote_id' => '401234',
                'display_name' => 'Alpha Chimney',
            ]);
            $c->connect([
                'refresh_token' => 'conn-jwt',
                'server' => 'https://platform.ringcentral.com',
                'client_id' => 'conn-id',
                'client_secret' => 'conn-secret',
                'jwt' => 'conn-jwt',
            ]);
            $c->save();

            return $c;
        });
    }

    private function fakeCallLog(): void
    {
        Http::fake([
            '*/restapi/oauth/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => [], 'paging' => ['page' => 1], 'navigation' => []]),
        ]);
    }
}
