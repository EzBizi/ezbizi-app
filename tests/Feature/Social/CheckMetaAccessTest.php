<?php

namespace Tests\Feature\Social;

use App\Console\Commands\CheckMetaAccess;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-02: A-1's WhatsApp lost Meta's permission and still read "connected" for four days. */
class CheckMetaAccessTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t, string $remoteId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $remoteId) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
                'provider' => SocialProvider::WhatsApp->value, 'remote_id' => $remoteId, 'display_name' => 'A1 Chimney']);
            $c->connect(['access_token' => 'tok-'.$remoteId]);
            $c->save();

            return $c;
        });
    }

    public function test_a_refused_connection_turns_broken_and_tells_who_can_connect_it_once_then_recovers(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $owner = $this->userWithRole($t, 'Marketing', ['phone.edit']);
        $lost = $this->connection($t, '111');
        $fine = $this->connection($t, '222');
        $down = $this->connection($t, '333');

        $refuse = true;
        Http::fake(function ($r) use (&$refuse) {
            return match (true) {
                str_contains($r->url(), '/111') => $refuse
                    ? Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400)
                    : Http::response(['id' => '111']),
                str_contains($r->url(), '/333') => Http::response('down', 503),
                default => Http::response(['id' => '222']),
            };
        });

        $this->artisan('ezbizi:meta-access-check')->assertSuccessful();
        $this->artisan('ezbizi:meta-access-check')->assertSuccessful();

        $status = fn (SocialConnection $c) => DB::table('social_connections')->where('id', $c->id)->value('status');
        $this->assertSame(SocialConnection::STATUS_BROKEN, $status($lost));
        $this->assertSame(CheckMetaAccess::REASON, DB::table('social_connections')->where('id', $lost->id)->value('status_reason'));
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $status($fine));
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $status($down));   // no answer is no verdict
        $this->assertSame(1, DB::table('notifications')->where('user_id', $owner->id)->count());   // said once

        $refuse = false;
        $this->artisan('ezbizi:meta-access-check')->assertSuccessful();
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $status($lost));
    }
}
