<?php

namespace Tests\Feature\Reviews;

use App\Models\AutomationEvent;
use App\Models\SocialConnection;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-15: A-1 Chimney's Business Profile on Rodrigo's grant, in the meantime. */
class GbpInterimConnectionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_only_accounts_that_add_a_location_are_connected_with_their_own_client(): void
    {
        $t = $this->provisionTenant();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response(['accounts' => [
                ['name' => 'accounts/G1', 'type' => 'LOCATION_GROUP', 'accountName' => 'A1 - Atlanta'],
                ['name' => 'accounts/P', 'type' => 'PERSONAL', 'accountName' => 'Rodrigo'],
                ['name' => 'accounts/G2', 'type' => 'LOCATION_GROUP', 'accountName' => 'A1 - New York'],
            ]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/P/locations*' => Http::response(['locations' => [['name' => 'locations/1'], ['name' => 'locations/2']]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/G1/locations*' => Http::response(['locations' => [['name' => 'locations/1']]]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/G2/locations*' => Http::response(['locations' => [['name' => 'locations/3']]]),
        ]);

        \App\Console\Commands\GbpInterimConnect::$stdin = json_encode(['client_id' => 'theirs', 'client_secret' => 's', 'refresh_token' => 'rt']);
        $this->artisan('ezbizi:gbp-interim-connect', ['company' => $t['company']->id])
            ->expectsOutputToContain('2 connection(s), 3 location(s)')
            ->assertSuccessful();
        \App\Console\Commands\GbpInterimConnect::$stdin = null;

        $rows = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', SocialProvider::GoogleBusinessProfile->value)->orderBy('remote_id')->get());
        $this->assertSame(['G2', 'P'], $rows->pluck('remote_id')->all(), 'Atlanta adds nothing the personal account lacks');
        $this->assertSame('company', $rows[0]->scope_type);
        $this->assertSame($t['company']->id, $rows[0]->scope_id);
        $this->assertSame('a1leads', $rows[0]->credentials['interim']);
    }

    public function test_a_connections_own_client_renews_it_and_the_first_review_import_is_silent(): void
    {
        $t = $this->provisionTenant();
        config(['services.business_profile.client_id' => 'ours', 'services.business_profile.client_secret' => 'our-secret']);
        $c = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value, 'remote_id' => 'P', 'display_name' => 'A-1']);
            $c->connect(['refresh_token' => 'rt', 'client_id' => 'theirs', 'client_secret' => 'their-secret']);
            $c->save();

            return $c;
        });
        $review = fn (string $id) => ['name' => "accounts/P/locations/1/reviews/{$id}", 'reviewer' => ['displayName' => 'Dana'],
            'starRating' => 'ONE', 'comment' => 'Late.', 'createTime' => '2026-10-14T09:00:00Z'];
        $reviews = [$review('a')];
        Http::fake(function (Request $r) use (&$reviews) {
            return match (true) {
                str_contains($r->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
                str_contains($r->url(), '/reviews') => Http::response(['reviews' => $reviews]),
                str_contains($r->url(), '/locations') => Http::response(['locations' => [['name' => 'accounts/P/locations/1']]]),
                default => Http::response([], 404),
            };
        });

        $this->inTenant($t['org'], fn () => GoogleToken::forConnection($c, 'business_profile'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2') && $r['client_id'] === 'theirs' && $r['client_secret'] === 'their-secret');

        $this->inTenant($t['org'], fn () => app(BusinessProfileReviews::class)->sync($c));
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.received')->count(), 'the backlog is not news');

        $reviews[] = $review('b');
        $this->inTenant($t['org'], fn () => app(BusinessProfileReviews::class)->sync($c->fresh()));
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.received')->count(), 'a new review after that is');
    }
}
