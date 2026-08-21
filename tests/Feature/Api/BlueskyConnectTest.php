<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class BlueskyConnectTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_handle_and_app_password_connect_the_account(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
                'did'        => 'did:plc:abc123',
                'handle'     => 'a1chimney.bsky.social',
                'accessJwt'  => 'an-access-jwt',
                'refreshJwt' => 'a-refresh-jwt',
            ]),
        ]);

        $row = $this->signedInAs($t['admin'])->postJson('/api/v1/social/bluesky/connect', [
            'handle'       => 'a1chimney.bsky.social',
            'app_password' => 'xxxx-xxxx-xxxx-xxxx',
        ])->assertOk()->json('data');

        $this->assertSame('Bluesky', $row['provider_label']);
        $this->assertSame('a1chimney.bsky.social', $row['display_name']);
        $this->assertStringNotContainsString('xxxx-xxxx', json_encode($row));

        $stored = $this->inTenant($t['org'], fn () => SocialConnection::sole());
        $this->assertSame('did:plc:abc123', $stored->remote_id);
        $this->assertSame('a-refresh-jwt', $stored->refreshToken());
        $this->assertStringNotContainsString('xxxx-xxxx', (string) json_encode($stored->toArray()));
    }

    public function test_a_wrong_app_password_connects_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response(['error' => 'AuthenticationRequired'], 401),
        ]);

        $this->signedInAs($t['admin'])->postJson('/api/v1/social/bluesky/connect', [
            'handle'       => 'a1chimney.bsky.social',
            'app_password' => 'wrong',
        ])->assertStatus(502);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::count()));
    }

    public function test_connecting_takes_marketing_edit(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['marketing.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/social/bluesky/connect', [
            'handle'       => 'a1chimney.bsky.social',
            'app_password' => 'xxxx',
        ])->assertForbidden();
    }
}
