<?php

namespace Tests\Feature\Reviews;

use App\Jobs\SyncBusinessProfileConnection;
use App\Models\SocialConnection;
use App\Services\Leads\BusinessProfileLocations;
use App\Services\Reviews\BusinessProfileReviews;
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

    /** 2026-11-02: DFW's 3,018 reviews timed the connect sync out at the default worker's 60 s. */
    public function test_the_listings_half_queues_the_reviews_on_the_long_worker(): void
    {
        Bus::fake();
        $t = $this->provisionTenant('A1-Group');
        $id = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
                'provider' => 'google_business_profile', 'remote_id' => '123', 'display_name' => 'A1']);
            $c->connect(['refresh_token' => 'r']);
            $c->save();

            return $c->id;
        });
        $locations = $this->mock(BusinessProfileLocations::class);
        $reviews = $this->mock(BusinessProfileReviews::class);

        $locations->shouldReceive('sync')->once();
        $reviews->shouldNotReceive('sync');
        app()->call([new SyncBusinessProfileConnection($id), 'handle']);
        Bus::assertDispatched(SyncBusinessProfileConnection::class, fn ($job) => $job->connectionId === $id && $job->reviews
            && $job->connection === 'database-long' && $job->queue === 'long' && $job->timeout === 190);

        $locations->shouldNotReceive('sync');
        $reviews->shouldReceive('sync')->once();
        app()->call([new SyncBusinessProfileConnection($id, true), 'handle']);
        $this->assertNull((new SyncBusinessProfileConnection($id))->timeout, 'the listings half keeps the worker limit');
    }
}
