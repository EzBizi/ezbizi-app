<?php

namespace Tests\Feature\Reviews;

use App\Jobs\SyncBusinessProfileConnection;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-29: a new Business Profile's listings arrived only after "Check on Google". */
class BusinessProfileConnectSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_connecting_a_profile_queues_its_sync_and_the_screen_says_syncing(): void
    {
        Bus::fake();
        config([
            'services.business_profile.client_id' => 'gbp-id',
            'services.business_profile.client_secret' => 'gbp-secret',
            'services.business_profile.redirect' => 'https://app.ezbizi.test/api/v1/social/business-profile/callback',
        ]);
        $t = $this->provisionTenant('A1-Group');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600]),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/123', 'accountName' => 'A1 Chimney - CT']]]),
        ]);

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/business-profile/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        auth()->forgetGuards();
        $this->get('/api/v1/social/business-profile/callback?code=c&state='.$q['state'])->assertOk();

        $id = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'google_business_profile')->sole()->id);
        Bus::assertDispatched(SyncBusinessProfileConnection::class, fn ($job) => $job->connectionId === $id);
        $this->signedInAs($t['admin'])->getJson('/api/v1/business-profile/locations')->assertOk()->assertJsonPath('meta.syncing', true);

        Cache::forget(SyncBusinessProfileConnection::SYNCING_KEY.$id);
        $this->signedInAs($t['admin'])->getJson('/api/v1/business-profile/locations')->assertOk()->assertJsonPath('meta.syncing', false);
    }
}
