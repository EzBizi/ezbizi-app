<?php

namespace Tests\Feature\Tasks;

use App\Models\SocialConnection;
use App\Support\Tasks\Monday\MondayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Prod, 2026-11-13: the all-boards import ran ~2 h on one client; the
 * 10-minute sync refreshed (rotated) the connection's token meanwhile, Monday
 * refused the old one, and 19 boards failed with "Reconnect Monday" while the
 * connection was fine. A connection's client asks for the current token.
 */
class MondayClientTokenTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_long_run_picks_up_a_token_rotated_under_it(): void
    {
        $t = $this->provisionTenant('Alpha');
        $connection = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group']);
            $c->connect(['access_token' => 'old-token', 'refresh_token' => null, 'scopes' => []]);
            $c->save();

            return $c;
        });
        $seen = [];
        Http::fake(['api.monday.com/*' => function (Request $r) use (&$seen) {
            $seen[] = $r->header('Authorization')[0] ?? '';

            return end($seen) === 'new-token'
                ? Http::response(['data' => ['me' => ['id' => '1']]])
                : Http::response(['errors' => [['message' => 'Not Authenticated']]], 401);
        }]);

        $client = MondayClient::for($connection);
        // Another run (the sync) refreshes the token: Monday now takes only the new one.
        $this->inTenant($t['org'], function () use ($connection) {
            $connection->connect(['access_token' => 'new-token', 'refresh_token' => null, 'scopes' => []]);
            $connection->save();
        });

        $this->assertSame(['me' => ['id' => '1']], $client->query('{ me { id } }'));
        $this->assertSame('new-token', end($seen));
    }
}
