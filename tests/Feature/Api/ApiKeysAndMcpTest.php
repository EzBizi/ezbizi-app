<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\McpController;
use App\Models\AuditEvent;
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
        $can = $this->keyFor($this->userWithRole($t, 'Fleet', ['fleet_management.general.view', 'api_keys.view']));
        $cannot = $this->keyFor($this->userWithRole($t, 'Nothing', ['api_keys.view']));

        $this->assertFalse($this->callApi($can, ['method' => 'GET', 'path' => 'vehicles/summary'])['isError']);

        $this->app['auth']->forgetGuards();
        $refused = $this->callApi($cannot, ['method' => 'GET', 'path' => 'vehicles/summary']);
        $this->assertTrue($refused['isError'], $refused['content'][0]['text']);
        $this->assertSame(403, json_decode($refused['content'][0]['text'], true)['status']);
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
        $this->assertSame(200, json_decode($me['content'][0]['text'], true)['status']);
        $this->assertTrue(json_decode($me['content'][0]['text'], true)['complete']);
    }

    private function level(string $key, string $method, string $path): ?string
    {
        return $this->withToken($key)->json($method, $path, [])->json('code');
    }

    public function test_the_role_decides_which_kind_of_key_a_person_may_make(): void
    {
        $t = $this->provisionTenant();

        $none = $this->userWithRole($t, 'None', ['customers.view']);
        $this->signedInAs($none)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'level' => 'viewer'])->assertForbidden();
        $this->signedInAs($none)->getJson('/api/v1/me/api-keys')->assertJsonPath('allowed_levels', []);

        $viewer = $this->userWithRole($t, 'Viewer', ['api_keys.view']);
        $this->signedInAs($viewer)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'level' => 'viewer'])->assertCreated();
        $this->signedInAs($viewer)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'level' => 'editor'])->assertForbidden();
        // An installed client asking for the old "write" asks for Full access.
        $this->signedInAs($viewer)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'access' => 'write'])->assertForbidden();

        $editor = $this->userWithRole($t, 'Editor', ['api_keys.edit']);
        $this->signedInAs($editor)->getJson('/api/v1/me/api-keys')->assertJsonPath('allowed_levels', ['viewer', 'editor']);
        $this->signedInAs($editor)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'level' => 'full'])->assertForbidden();
    }

    public function test_an_editor_key_edits_but_cannot_delete(): void
    {
        $t = $this->provisionTenant();
        $key = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/me/api-keys', ['name' => 'Ed', 'level' => 'editor'])
            ->assertCreated()->assertJsonPath('level', 'editor')->json('key');
        $this->app['auth']->forgetGuards();

        $this->assertNotContains($this->level($key, 'PATCH', '/api/v1/me/preferences'), ['api_key_read_only', 'api_key_no_delete']);
        $this->assertSame('api_key_no_delete', $this->level($key, 'DELETE', '/api/v1/me/signature'));
    }

    public function test_narrowing_the_role_narrows_keys_already_made(): void
    {
        $t = $this->provisionTenant();
        $user = $this->userWithRole($t, 'Full', ['api_keys.delete']);
        $key = $this->keyFor($user, 'write');

        $this->assertNotSame('api_key_no_delete', $this->level($key, 'DELETE', '/api/v1/me/signature'));

        $this->inTenant($t['org'], fn () => $user->role->syncPermissionKeys(['api_keys.view']));
        $this->app['auth']->forgetGuards();
        $this->assertSame('api_key_read_only', $this->level($key, 'DELETE', '/api/v1/me/signature'));

        $this->inTenant($t['org'], fn () => $user->role->syncPermissionKeys(['customers.view']));
        $this->app['auth']->forgetGuards();
        $this->assertSame('api_key_not_allowed', $this->level($key, 'GET', '/api/v1/me'));
    }

    public function test_every_keyed_call_is_recorded_and_the_mcp_wrapper_is_not(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin'], 'write');

        $this->callApi($key, ['method' => 'PATCH', 'path' => 'me/preferences', 'body' => ['preferences' => ['appearance.theme' => 'dark']]]);
        $this->withToken($key)->getJson('/api/v1/me')->assertOk();
        $this->withToken($key)->postJson('/api/v1/me/password', [])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity')->assertOk()->json('activity');
        $requests = array_values(array_filter($rows, fn ($r) => $r['action'] === 'api_key.request'));

        $this->assertSame(
            [['POST', 'api/v1/me/password', 403, 'api'], ['GET', 'api/v1/me', 200, 'api'], ['PATCH', 'api/v1/me/preferences', 200, 'mcp']],
            array_map(fn ($r) => [$r['method'], $r['path'], $r['status'], $r['via']], $requests),
        );
        $this->assertSame(['preferences' => ['appearance.theme' => 'dark']], $requests[2]['sent']);
        $this->assertNull($requests[1]['sent']);

        // What the record looked like before the change, for putting it back.
        $event = AuditEvent::query()->where('action', 'api_key.request')->where('metadata->method', 'PATCH')->sole();
        $before = json_decode($event->changes['before'], true);
        $after = $this->signedInAs($t['admin'])->getJson('/api/v1/me/preferences')->json();
        $this->assertNotSame('dark', data_get($before, 'data.appearance.theme.value', data_get($before, 'data.appearance.theme')));
        $this->assertNotEquals($after, $before);
    }

    public function test_activity_is_your_own_unless_you_are_an_administrator(): void
    {
        $t = $this->provisionTenant();
        $staff = $this->userWithRole($t, 'Staff', ['api_keys.view']);
        $adminKey = $this->keyFor($t['admin']);
        $staffKey = $this->keyFor($staff);

        $this->withToken($adminKey)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($staffKey)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();

        $mine = $this->signedInAs($staff)->getJson('/api/v1/api-keys/activity')->json('activity');
        $this->assertSame([$staff->getKey()], array_values(array_unique(array_column($mine, 'user_id'))));

        $all = $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity')->json('activity');
        $this->assertEqualsCanonicalizing([$staff->getKey(), $t['admin']->getKey()], array_values(array_unique(array_column($all, 'user_id'))));

        $one = $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity?user_id='.$staff->getKey())->json('activity');
        $this->assertSame([$staff->getKey()], array_values(array_unique(array_column($one, 'user_id'))));
    }

    /** UI queue 111: the tiles above the list. */
    public function test_the_summary_counts_the_last_day_of_calls_the_viewer_may_see(): void
    {
        $t = $this->provisionTenant();
        $staff = $this->userWithRole($t, 'Staff', ['api_keys.view']);
        $adminKey = $this->keyFor($t['admin'], 'write');
        $staffKey = $this->keyFor($staff);

        $this->withToken($adminKey)->getJson('/api/v1/me')->assertOk();
        $this->withToken($adminKey)->patchJson('/api/v1/me/preferences', ['preferences' => ['appearance.theme' => 'dark']])->assertOk();
        $this->withToken($adminKey)->postJson('/api/v1/me/password', [])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($staffKey)->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();

        // Two days old, so outside the day the tiles describe.
        AuditEvent::query()->where('action', 'api_key.request')->where('metadata->method', 'PATCH')->sole()
            ->replicate()->forceFill(['occurred_at' => now()->subDays(2)])->save();

        $this->assertSame(
            ['keys' => 2, 'calls' => 4, 'changes' => 2, 'refused' => 1],
            $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity')->assertOk()->json('summary'),
        );
        // Paging back does not move the tiles.
        $this->assertSame(
            ['keys' => 2, 'calls' => 4, 'changes' => 2, 'refused' => 1],
            $this->getJson('/api/v1/api-keys/activity?before='.urlencode(now()->subYear()->toIso8601String()))->json('summary'),
        );
        $this->assertSame(
            ['keys' => 1, 'calls' => 1, 'changes' => 0, 'refused' => 0],
            $this->signedInAs($staff)->getJson('/api/v1/api-keys/activity')->assertOk()->json('summary'),
        );
    }

    public function test_secrets_a_key_sends_are_never_logged(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin'], 'write');

        $this->withToken($key)->patchJson('/api/v1/me/preferences', [
            'preferences' => ['appearance.theme' => 'dark'],
            'smtp' => ['host' => 'mail.example.test', 'password' => 'hunter2-nested'],
            'api_token' => 'wk-live-123', 'auth_secret' => 'sek-456', 'current_password' => 'old-pass-789',
        ]);

        $row = json_encode(AuditEvent::query()->where('action', 'api_key.request')->sole()->changes);

        foreach (['hunter2-nested', 'wk-live-123', 'sek-456', 'old-pass-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $row);
        }
        $this->assertStringContainsString('mail.example.test', $row);
    }

    /** The keyed change's activity row, read as the given person. */
    private function lastChange($viewer): array
    {
        $this->app['auth']->forgetGuards();

        return collect($this->signedInAs($viewer)->getJson('/api/v1/api-keys/activity')->assertOk()->json('activity'))
            ->first(fn ($r) => $r['action'] === 'api_key.request' && $r['method'] !== 'GET');
    }

    public function test_a_website_text_changed_by_a_key_can_be_put_back(): void
    {
        $t = $this->provisionTenant();
        $site = $this->signedInAs($t['admin'])->postJson('/api/v1/web/sites', [
            'company_id' => $t['company']->id, 'host' => 'a-1chimney.com',
        ])->assertCreated()->json('data.id');
        $values = "/api/v1/web/sites/{$site}/values";
        $this->signedInAs($t['admin'])->putJson($values, ['path' => '/about-us', 'values' => ['phone' => '555-0100']])->assertOk();

        $key = $this->keyFor($t['admin'], 'write');
        $this->callApi($key, ['method' => 'PUT', 'path' => "web/sites/{$site}/values", 'body' => ['path' => '/about-us', 'values' => ['phone' => '555-0999']]]);

        $row = $this->lastChange($t['admin']);
        $this->assertTrue($row['revertable']);

        $this->signedInAs($t['admin'])->postJson("/api/v1/api-keys/activity/{$row['id']}/revert")
            ->assertOk()->assertJsonPath('reverted', true);
        $this->assertSame('555-0100', $this->signedInAs($t['admin'])->getJson($values)->json('data.pages./about-us.phone'));

        $this->signedInAs($t['admin'])->postJson("/api/v1/api-keys/activity/{$row['id']}/revert")->assertStatus(409);
        $this->assertFalse($this->lastChange($t['admin'])['revertable']);
    }

    public function test_a_change_edited_again_since_is_not_put_back(): void
    {
        $t = $this->provisionTenant();
        $customer = $this->signedInAs($t['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $t['franchise']->id, 'name' => 'Original Name',
        ])->assertCreated()->json('data.id');

        $key = $this->keyFor($t['admin'], 'write');
        $this->withToken($key)->patchJson("/api/v1/customers/{$customer}", ['name' => 'AI Name'])->assertOk();
        $row = $this->lastChange($t['admin']);
        $this->assertTrue($row['revertable']);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/customers/{$customer}", ['name' => 'Person Fixed It'])->assertOk();

        $this->signedInAs($t['admin'])->postJson("/api/v1/api-keys/activity/{$row['id']}/revert")->assertStatus(409);
        $this->assertSame('Person Fixed It', $this->signedInAs($t['admin'])->getJson("/api/v1/customers/{$customer}")->json('data.name'));
    }

    public function test_a_customer_changed_by_a_key_can_be_put_back(): void
    {
        $t = $this->provisionTenant();
        $customer = $this->signedInAs($t['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $t['franchise']->id, 'name' => 'Original Name',
        ])->assertCreated()->json('data.id');

        $key = $this->keyFor($t['admin'], 'write');
        $this->withToken($key)->patchJson("/api/v1/customers/{$customer}", ['name' => 'AI Name'])->assertOk();

        $row = $this->lastChange($t['admin']);
        $this->signedInAs($t['admin'])->postJson("/api/v1/api-keys/activity/{$row['id']}/revert")
            ->assertOk()->assertJsonPath('reverted', true);
        $this->assertSame('Original Name', $this->signedInAs($t['admin'])->getJson("/api/v1/customers/{$customer}")->json('data.name'));
    }

    public function test_a_change_outside_the_allowlist_offers_no_put_back(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin'], 'write');
        $this->withToken($key)->patchJson('/api/v1/me/preferences', ['preferences' => ['appearance.theme' => 'dark']])->assertOk();

        $row = $this->lastChange($t['admin']);
        $this->assertFalse($row['revertable']);
        $this->signedInAs($t['admin'])->postJson("/api/v1/api-keys/activity/{$row['id']}/revert")->assertStatus(422);
    }

    /** Every call_api result parses, and says whether it is all of the answer. */
    private function apiResult(string $key, array $arguments): array
    {
        $text = $this->callApi($key, $arguments)['content'][0]['text'];
        $this->assertJson($text);

        return json_decode($text, true);
    }

    public function test_a_large_answer_is_never_cut_into_broken_json(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);
        $whole = $this->signedInAs($t['admin'])->getJson('/api/v1/me')->json();
        $this->app['auth']->forgetGuards();

        McpController::$maxChars = 200;
        try {
            // An object too big to send comes back as an outline of its keys.
            $big = $this->apiResult($key, ['method' => 'GET', 'path' => 'me']);
            $this->assertFalse($big['complete']);
            $this->assertArrayHasKey('data', $big['outline']);

            // Picking part of it returns that part exactly.
            $name = $this->apiResult($key, ['method' => 'GET', 'path' => 'me', 'pick' => 'data.name']);
            $this->assertTrue($name['complete']);
            $this->assertSame($whole['data']['name'], $name['body']);

            $missing = $this->apiResult($key, ['method' => 'GET', 'path' => 'me', 'pick' => 'data.nope']);
            $this->assertFalse($missing['complete']);
            $this->assertStringContainsString('Nothing at pick', $missing['error']);
        } finally {
            McpController::$maxChars = 100_000;
        }
    }

    public function test_a_long_list_pages_until_every_item_is_read(): void
    {
        $t = $this->provisionTenant();
        foreach (range(1, 12) as $i) {
            $this->signedInAs($t['admin'])->postJson('/api/v1/customers', [
                'owning_franchise_id' => $t['franchise']->id, 'name' => "Customer number {$i} with a longish name",
            ])->assertCreated();
        }
        $key = $this->keyFor($t['admin']);

        McpController::$maxChars = 1500;
        try {
            [$seen, $offset, $calls] = [[], 0, 0];
            do {
                $page = $this->apiResult($key, ['method' => 'GET', 'path' => 'customers', 'query' => ['per_page' => 50], 'pick' => 'data', 'offset' => $offset]);
                $this->assertGreaterThan(0, $page['returned']);
                $seen = [...$seen, ...array_column($page['body'], 'id')];
                $offset = $page['next_offset'] ?? null;
                $calls++;
            } while ($offset !== null && $calls < 20);

            $this->assertGreaterThan(1, $calls, 'the cap should have forced more than one page');
            $this->assertTrue($page['complete']);
            $this->assertCount(12, array_unique($seen));
        } finally {
            McpController::$maxChars = 100_000;
        }
    }

    /** The search bar: `q` narrows by path, key, person or action; a blank `q` is no filter. */
    public function test_activity_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant();
        $key = $this->keyFor($t['admin']);
        $this->withToken($key)->getJson('/api/v1/me')->assertOk();
        $this->withToken($key)->getJson('/api/v1/me/preferences')->assertOk();
        $this->app['auth']->forgetGuards();

        $all = $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity')->assertOk()->json('activity');
        $this->assertGreaterThan(2, count($all));

        $found = $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity?q=preferences')->assertOk()->json('activity');
        $this->assertSame(['api/v1/me/preferences'], array_column($found, 'path'));

        $this->assertCount(count($all), $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity?q=')->json('activity'));
        $this->assertCount(0, $this->signedInAs($t['admin'])->getJson('/api/v1/api-keys/activity?q=%25')->json('activity'));
    }
}
