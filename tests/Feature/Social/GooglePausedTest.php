<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\GoogleToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover, 2026-10-16: one switch pauses every Google call for a business. */
class GooglePausedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_paused_business_sends_nothing_to_google_and_stays_connected(): void
    {
        $t = $this->provisionTenant('A1-Group');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600])]);
        config()->set('services.google_ads.client_id', 'cid');
        config()->set('services.google_ads.client_secret', 'cs');

        $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY, 'scope_id' => $t['company']->id,
                'provider' => 'google_ads', 'remote_id' => '7061094046', 'display_name' => '706-109-4046',
            ]);
            $row->connect(['refresh_token' => 'rt']);
            $row->save();

            $this->assertSame('at', GoogleToken::forConnection($row, 'google_ads'), 'running: a token');

            app(SettingsResolver::class)->set('leads.google_paused', $t['company'], true);
            try {
                GoogleToken::forConnection($row, 'google_ads');
                $this->fail('a paused business got a token');
            } catch (RuntimeException $e) {
                $this->assertSame(GoogleToken::PAUSED, $e->getMessage());
            }
            $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->refresh()->status);
        });

        Http::assertSentCount(1);
    }
}
