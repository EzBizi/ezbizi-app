<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\PostPayload;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trial access: Pinterest refuses Pins on api.pinterest.com, so with the
 * sandbox token configured the Pin goes to the sandbox, with that token, on
 * the named board.
 */
class PinterestSandboxTest extends TestCase
{
    public function test_with_a_sandbox_token_the_pin_goes_to_the_sandbox_on_the_named_board(): void
    {
        config(['services.pinterest.sandbox_token' => 'pina_SANDBOX', 'services.pinterest.sandbox_board' => 'board-9']);
        Http::fake(['api-sandbox.pinterest.com/v5/pins' => Http::response(['id' => 'pin-1'], 201)]);

        $this->assertSame('pin-1', (new PinterestPublisher)->publish($this->connection(), $this->photo()));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api-sandbox.pinterest.com/v5/pins')
            && $r->header('Authorization')[0] === 'Bearer pina_SANDBOX'
            && $r->data()['board_id'] === 'board-9');
    }

    public function test_without_one_it_posts_to_production_with_the_connection_token(): void
    {
        config(['services.pinterest.sandbox_token' => null]);
        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'b-1']]]),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-2'], 201),
        ]);

        $this->assertSame('pin-2', (new PinterestPublisher)->publish($this->connection(), $this->photo()));

        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.pinterest.com/v5/pins')
            && $r->header('Authorization')[0] === 'Bearer a-token');
    }

    private function connection(): SocialConnection
    {
        $c = new SocialConnection;
        $c->forceFill(['provider' => 'pinterest', 'remote_id' => 'u', 'display_name' => 'EzBizi']);
        $c->connect(['refresh_token' => 'r', 'access_token' => 'a-token']);

        return $c;
    }

    private function photo(): PostPayload
    {
        $path = tempnam(sys_get_temp_dir(), 'pin').'.png';
        file_put_contents($path, 'PNGBYTES');

        return new PostPayload(body: 'Chimney day', filePath: $path);
    }
}
