<?php

namespace Tests\Feature\Api;

use App\Models\BusinessLocation;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Business Profile — the listings the public sees.
 *
 * The cases worth holding: a listing Google has stopped showing must be
 * findable, a listing is not a franchise, and nothing here writes back to
 * Google as a side effect of anything.
 */
class BusinessProfileApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/business-profile/locations';

    /** @param array{org: Organization} $t */
    private function location(array $t, array $attributes = []): BusinessLocation
    {
        return $this->inTenant($t['org'], fn () => BusinessLocation::create([
            'organization_id'    => $t['org']->id,
            'remote_name'        => 'locations/'.bin2hex(random_bytes(5)),
            'title'              => 'A-1 Chimney Hallandale',
            'phone'              => '(833) 239-2494',
            'address_line1'      => '800 SE 4th Ave',
            'city'               => 'Hallandale Beach',
            'state'              => 'FL',
            'postal_code'        => '33009',
            'primary_category'   => 'Chimney sweep',
            'verification_state' => BusinessLocation::VERIFIED,
            'is_published'       => true,
            ...$attributes,
        ]));
    }

    public function test_one_failing_profile_does_not_turn_an_empty_result_into_an_error(): void
    {
        $t = $this->provisionTenant('A1-Chimney');

        foreach (['acct-good', 'acct-bad'] as $account) {
            $this->inTenant($t['org'], function () use ($t, $account) {
                $c = new SocialConnection;
                $c->forceFill([
                    'organization_id' => $t['org']->id,
                    'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                    'scope_id'        => $t['org']->id,
                    'provider'        => SocialProvider::GoogleBusinessProfile->value,
                    'remote_id'       => $account,
                    'display_name'    => $account,
                ]);
                $c->connect(['refresh_token' => 'rt-'.$account]);
                $c->save();
            });
        }

        config([
            'services.business_profile.client_id'     => 'client.apps.googleusercontent.com',
            'services.business_profile.client_secret' => 'client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'mybusinessbusinessinformation.googleapis.com/v1/*acct-good/locations*' => Http::response(['locations' => []], 200),
            'mybusinessbusinessinformation.googleapis.com/v1/*acct-bad/locations*'  => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/business-profile/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 0)
            ->assertJsonCount(1, 'data.failures');
    }

    /** Scheduling › Locations, 2026-11-05: a listing with no storefront is placed by its service area. */
    public function test_a_service_area_listing_keeps_its_places(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value,
                'remote_id' => 'acct-1',
                'display_name' => 'acct-1',
            ]);
            $c->connect(['refresh_token' => 'rt-1']);
            $c->save();
        });
        config([
            'services.business_profile.client_id' => 'client.apps.googleusercontent.com',
            'services.business_profile.client_secret' => 'client-secret',
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            'mybusinessbusinessinformation.googleapis.com/v1/*acct-1/locations*' => Http::response(['locations' => [
                ['name' => 'locations/1', 'title' => 'A1 Chimney', 'serviceArea' => ['businessType' => 'CUSTOMER_LOCATION_ONLY',
                    'places' => ['placeInfos' => [['placeName' => 'Warren, MI, USA', 'placeId' => 'p1'], ['placeName' => 'Detroit, MI, USA', 'placeId' => 'p2']]]]],
                // An area given only as a region has no places, and must not break the read.
                ['name' => 'locations/2', 'title' => 'A1 Region', 'serviceArea' => ['businessType' => 'CUSTOMER_LOCATION_ONLY', 'regionCode' => 'US']],
            ]], 200),
        ]);

        $this->signedInAs($t['admin'])->postJson('/api/v1/business-profile/sync')->assertOk()->assertJsonPath('data.fetched', 2);

        Http::assertSent(fn ($r) => str_contains((string) urldecode($r->url()), 'serviceArea'));
        $places = $this->inTenant($t['org'], fn () => BusinessLocation::query()->orderBy('remote_name')->pluck('service_places')->all());
        $this->assertSame([['Warren, MI, USA', 'Detroit, MI, USA'], null], $places);
    }

    public function test_a_listing_is_shown_with_its_address_on_one_line(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->location($t);

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame('A-1 Chimney Hallandale', $response->json('data.0.title'));
        $this->assertSame(
            '800 SE 4th Ave, Hallandale Beach, FL, 33009',
            $response->json('data.0.address'),
        );
        $this->assertTrue($response->json('data.0.is_live'));
    }

    /**
     * The one this module exists for.
     *
     * A suspended listing is invisible on Maps and looks perfectly healthy in a
     * database. Somebody has to be able to find it without knowing to look.
     */
    public function test_a_suspended_listing_is_counted_and_findable(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->location($t);
        $this->location($t, [
            'title'              => 'A-1 Chimney Aventura',
            'verification_state' => BusinessLocation::SUSPENDED,
        ]);

        $all = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();
        $this->assertSame(2, $all->json('meta.total'));
        $this->assertSame(1, $all->json('meta.not_live'));

        $bad = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?live=no')->assertOk();
        $this->assertSame(1, $bad->json('meta.total'));
        $this->assertSame('A-1 Chimney Aventura', $bad->json('data.0.title'));
        $this->assertFalse($bad->json('data.0.is_live'));
    }

    public function test_a_published_flag_of_false_is_not_live_even_when_verified(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->location($t, ['is_published' => false]);

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertFalse($response->json('data.0.is_live'));
        $this->assertSame(1, $response->json('meta.not_live'));
    }

    public function test_another_organizations_listings_are_never_shown(): void
    {
        $mine   = $this->provisionTenant('Mine');
        $theirs = $this->provisionTenant('Theirs');

        $this->location($theirs, ['title' => 'Not Yours']);

        $response = $this->signedInAs($mine['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame(0, $response->json('meta.total'));
    }

    /** A listing is not a franchise; the link is set by a person. */
    public function test_a_listing_can_be_put_on_a_branch(): void
    {
        $t        = $this->provisionTenant('A1-Group');
        $location = $this->location($t);

        $this->signedInAs($t['admin'])
            ->patchJson(self::ENDPOINT.'/'.$location->id, [
                'franchise_id' => $t['franchise']->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.franchise.name', $t['franchise']->name);
    }

    public function test_a_branch_from_another_organization_is_refused(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $other = $this->provisionTenant('Somebody-Else');
        $mine  = $this->location($t);

        $this->signedInAs($t['admin'])
            ->patchJson(self::ENDPOINT.'/'.$mine->id, [
                'franchise_id' => $other['franchise']->id,
            ])
            ->assertStatus(422);
    }

    /**
     * Nothing on a listing is editable except which branch answers for it.
     *
     * Title, address, phone and hours are Google's. An endpoint that accepted
     * them would be writing to a business's public shop front as a side effect
     * of an ordinary edit.
     */
    public function test_googles_own_fields_cannot_be_edited(): void
    {
        $t        = $this->provisionTenant('A1-Group');
        $location = $this->location($t);

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$location->id, [
            'franchise_id' => null,
            'title'        => 'Renamed By Hand',
            'phone'        => '(555) 000-0000',
        ])->assertOk();

        $fresh = $this->inTenant($t['org'], fn () => BusinessLocation::find($location->id));

        $this->assertSame('A-1 Chimney Hallandale', $fresh->title);
        $this->assertSame('(833) 239-2494', $fresh->phone);
    }

    public function test_there_is_no_way_to_create_a_listing_by_hand(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['title' => 'Invented'])
            ->assertStatus(405);
    }

    public function test_the_list_is_refused_without_the_tab(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->location($t);

        $this->signedInAs($viewer)
            ->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'see this location')
                    && ! str_contains($m, 'business_profile.view'),
            );
    }

    public function test_syncing_with_nothing_connected_says_so(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/business-profile/sync')
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'No Business Profile is connected'),
            );
    }

    public function test_a_connected_profile_shows_in_the_list_state(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'provider'        => SocialProvider::GoogleBusinessProfile->value,
            'remote_id'       => 'accounts/123',
            'display_name'    => 'A-1 Chimney',
        ]));

        $response = $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)->assertOk();

        $this->assertSame(
            'google_business_profile',
            $response->json('meta.connections.0.provider'),
        );
    }
}
