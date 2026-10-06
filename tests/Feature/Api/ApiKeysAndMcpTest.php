<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * An API key is its owner, minus the account's own security; the MCP endpoint
 * is the API, reached through the same gates.
 */
class ApiKeysAndMcpTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function keyFor($user, string $access = 'read'): string
    {
        $key = $this->signedInAs($user)
            ->postJson('/api/v1/me/api-keys', ['name' => 'Claude', 'access' => $access])
            ->assertCreated()
            ->assertJsonPath('access', $access)
            ->json('key');

        $this->app['auth']->forgetGuards();

        return $key;
    }

    private function mcp(string $key, string $method, array $params = []): array
    {
        return $this->withToken($key)
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params])
            ->assertOk()
            ->json();
    }

    private function callApi(string $key, array $arguments): array
    {
        return $this->mcp($key, 'tools/call', ['name' => 'call_api', 'arguments' => $arguments])['result'];
    }

    public function test_a_read_key_reads_as_its_owner_and_refuses_writes(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);

        $this->withToken($key)->getJson('/api/v1/me')->assertOk();
        $this->withToken($key)->patchJson('/api/v1/me/preferences', [])
            ->assertForbidden()->assertJsonPath('code', 'api_key_read_only');
    }

    public function test_no_key_can_touch_the_accounts_own_security(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin'], 'write');

        $this->withToken($key)->postJson('/api/v1/me/api-keys', ['name' => 'More'])
            ->assertForbidden()->assertJsonPath('code', 'api_key_account_security');
        $this->withToken($key)->postJson('/api/v1/me/password', [])
            ->assertForbidden()->assertJsonPath('code', 'api_key_account_security');
        $this->withToken($key)->deleteJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('code', 'api_key_account_security');
    }

    public function test_a_key_never_idles_out_and_is_not_a_session(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);

        $this->withToken($key)->getJson('/api/v1/me')->assertOk();

        $token = PersonalAccessToken::findToken($key);
        $this->assertNull($token->expires_at);

        $this->signedInAs($t['admin'])->getJson('/api/v1/me/sessions')
            ->assertOk()->assertJsonMissing(['name' => 'Claude']);
        $this->signedInAs($t['admin'])->getJson('/api/v1/me/api-keys')
            ->assertOk()->assertJsonPath('api_keys.0.name', 'Claude');
    }

    /** A client names its session; it must not be able to name it into a key. */
    public function test_a_session_named_like_a_key_is_still_a_session(): void
    {
        $t = $this->provisionTenant();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $t['admin']->email, 'password' => 'a-real-password', 'device_name' => 'api-key:x',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/me/sessions')
            ->assertOk()->assertJsonFragment(['name' => 'api-key:x']);
        $this->withToken($token)->getJson('/api/v1/me/api-keys')
            ->assertOk()->assertJsonCount(0, 'api_keys');
        $this->assertNotNull(PersonalAccessToken::findToken($token)->expires_at);
    }

    public function test_a_revoked_key_stops_working(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);
        $id = PersonalAccessToken::findToken($key)->id;

        $this->signedInAs($t['admin'])->deleteJson("/api/v1/me/api-keys/{$id}")->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->withToken($key)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_mcp_handshake_and_tool_list(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);

        $init = $this->mcp($key, 'initialize', ['protocolVersion' => '2025-06-18']);
        $this->assertSame('2025-06-18', $init['result']['protocolVersion']);

        $tools = array_column($this->mcp($key, 'tools/list')['result']['tools'], 'name');
        $this->assertSame(['list_endpoints', 'call_api'], $tools);

        $this->withToken($key)
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202);

        $list = $this->mcp($key, 'tools/call', ['name' => 'list_endpoints', 'arguments' => ['search' => 'leads']]);
        $this->assertStringContainsString('GET leads ', $list['result']['content'][0]['text']);
        $this->assertStringNotContainsString('staff/', $list['result']['content'][0]['text']);
    }

    public function test_mcp_calls_meet_the_owners_permissions(): void
    {
        $t = $this->provisionTenant();
        $can = $this->keyFor($this->userWithRole($t, 'Fleet', ['fleet_management.general.view']));
        $cannot = $this->keyFor($this->userWithRole($t, 'Nothing', []));

        $this->assertFalse($this->callApi($can, ['method' => 'GET', 'path' => 'vehicles/summary'])['isError']);

        $this->app['auth']->forgetGuards();
        $refused = $this->callApi($cannot, ['method' => 'GET', 'path' => 'vehicles/summary']);
        $this->assertTrue($refused['isError'], $refused['content'][0]['text']);
        $this->assertStringStartsWith('HTTP 403', $refused['content'][0]['text']);
    }

    public function test_mcp_keeps_read_only_and_blocked_paths(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);

        $write = $this->callApi($key, ['method' => 'PATCH', 'path' => 'me/preferences', 'body' => []]);
        $this->assertStringContainsString('api_key_read_only', $write['content'][0]['text']);

        foreach (['staff/health', 'mcp', 'auth/logout', '../up'] as $path) {
            $this->assertStringStartsWith('Refused', $this->callApi($key, ['method' => 'GET', 'path' => $path])['content'][0]['text'], $path);
        }

        $me = $this->callApi($key, ['method' => 'GET', 'path' => 'me']);
        $this->assertStringStartsWith('HTTP 200', $me['content'][0]['text']);
    }
}
