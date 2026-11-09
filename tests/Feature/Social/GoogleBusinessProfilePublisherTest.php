<?php

namespace Tests\Feature\Social;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Rodrigo's Social Poster target, taken over (2026-10-15): a Business Profile post. */
class GoogleBusinessProfilePublisherTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connected(array $t, array $locations): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $locations) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => SocialProvider::GoogleBusinessProfile->value,
                'remote_id' => 'accounts/111', 'display_name' => 'A-1 on Maps']);
            $c->connect(['refresh_token' => 'rt-test']);
            $c->save();
            foreach ($locations as $name) {
                BusinessLocation::create(['organization_id' => $t['org']->id, 'connection_id' => $c->id,
                    'remote_name' => $name, 'account_name' => 'accounts/111', 'title' => 'A-1 Chimney']);
            }

            return $c;
        });
    }

    /** Bug review 2026-11-09 #13: a refused photo post stops being fetchable at once. */
    public function test_a_refused_photo_post_releases_its_handoff(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\URL::forceScheme('https');
        \Illuminate\Support\Facades\URL::forceRootUrl('https://app.ezbizi.com');
        $t = $this->provisionTenant();
        $c = $this->connected($t, ['locations/222']);
        $this->fake(['mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts' => Http::response(['error' => ['message' => 'Quota exceeded']], 429)]);
        $photo = tempnam(sys_get_temp_dir(), 'gbp').'.jpg';
        file_put_contents($photo, 'JPEGBYTES');

        try {
            $this->inTenant($t['org'], fn () => Publishers::for(SocialProvider::GoogleBusinessProfile)
                ->publish($c, new PostPayload('Chimney season is here.', filePath: $photo)));
            $this->fail('A refused post came back as sent.');
        } catch (SocialPublishFailed) {
        }

        Http::assertSent(fn (Request $r) => isset($r['media']));
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('social-handoff'));
    }

    private function fake(array $routes): void
    {
        config(['services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]), ...$routes]);
    }

    public function test_words_post_to_the_accounts_one_location_and_the_id_comes_back(): void
    {
        $t = $this->provisionTenant();
        $c = $this->connected($t, ['locations/222']);
        $this->fake(['mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts' => Http::response(['name' => 'accounts/111/locations/222/localPosts/9'])]);

        $id = $this->inTenant($t['org'], fn () => Publishers::for(SocialProvider::GoogleBusinessProfile)
            ->publish($c, new PostPayload('Chimney season is here.', options: ['cta_type' => 'call'])));

        $this->assertSame('accounts/111/locations/222/localPosts/9', $id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v4/accounts/111/locations/222/localPosts')
            && $r['summary'] === 'Chimney season is here.' && $r['topicType'] === 'STANDARD'
            && $r['callToAction'] === ['actionType' => 'CALL']);
    }

    public function test_a_post_links_to_the_page_google_shows_it_on(): void
    {
        $t = $this->provisionTenant();
        $c = $this->connected($t, ['locations/222']);
        $this->fake(['mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts/9' => Http::response(['searchUrl' => 'https://local.google.com/place?id=1&use=posts'])]);

        $url = $this->inTenant($t['org'], fn () => Publishers::for(SocialProvider::GoogleBusinessProfile)
            ->permalink($c, 'accounts/111/locations/222/localPosts/9'));

        $this->assertSame('https://local.google.com/place?id=1&use=posts', $url);
    }

    public function test_what_it_cannot_do_is_said_before_google_is_asked(): void
    {
        $t = $this->provisionTenant();
        $two = $this->connected($t, ['locations/1', 'locations/2']);
        $this->fake([]);
        $publisher = Publishers::for(SocialProvider::GoogleBusinessProfile);

        $this->assertNotNull($publisher->cannotSend(\App\Support\Social\SocialMediaKind::Video));
        $this->assertSame([\App\Support\Social\SocialMediaKind::Text, \App\Support\Social\SocialMediaKind::Image], SocialProvider::GoogleBusinessProfile->accepts());

        try {
            $this->inTenant($t['org'], fn () => $publisher->publish($two, new PostPayload('Hello')));
            $this->fail('two locations and none chosen must be refused');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString('2 locations', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'localPosts'));
    }

    public function test_no_quota_says_so_and_list_and_delete_use_the_post_name(): void
    {
        $t = $this->provisionTenant();
        $c = $this->connected($t, ['accounts/111/locations/222']);
        $this->fake([
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts?*' => Http::response(['localPosts' => [['name' => 'accounts/111/locations/222/localPosts/9']]]),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts/9' => Http::response([]),
            'mybusiness.googleapis.com/v4/accounts/111/locations/222/localPosts' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
        ]);
        $publisher = Publishers::for(SocialProvider::GoogleBusinessProfile);

        $this->inTenant($t['org'], function () use ($publisher, $c) {
            try {
                $publisher->publish($c, new PostPayload('Hello'));
                $this->fail('a 429 must be refused');
            } catch (SocialPublishFailed $e) {
                $this->assertStringContainsString('quota is not approved', $e->getMessage());
            }

            $this->assertSame('accounts/111/locations/222/localPosts/9', $publisher->posts($c, 'accounts/111/locations/222')[0]['name']);
            $publisher->delete($c, 'accounts/111/locations/222/localPosts/9');
            $this->expectException(SocialPublishFailed::class);
            $publisher->delete($c, '../../accounts');
        });
    }
}
