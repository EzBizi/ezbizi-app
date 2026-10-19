<?php

namespace Tests\Feature\Reviews;

use App\Models\AutomationEvent;
use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Services\Leads\BusinessProfileLocations;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1 Leads takeover (2026-10-19): a listing Google stops returning is "not
 * listed", raised as a change; and the day's changes and reviews arrive as one
 * daily digest event for rules, never on a quiet day.
 */
class BusinessProfileStandingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $c;

    /** @var list<array<string, mixed>> */
    private array $locations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        $this->c = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => 'organization', 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value, 'remote_id' => 'accounts/P', 'display_name' => 'Co']);
            $c->connect(['refresh_token' => 'rt', 'client_id' => 'c', 'client_secret' => 's']);
            $c->save();

            return $c;
        });
        $this->locations = [
            ['name' => 'locations/1', 'title' => 'North', 'phoneNumbers' => ['primaryPhone' => '555-0101'], 'openInfo' => ['status' => 'OPEN'], 'metadata' => ['hasVoiceOfMerchant' => true]],
            ['name' => 'locations/2', 'title' => 'South', 'openInfo' => ['status' => 'OPEN'], 'metadata' => ['hasVoiceOfMerchant' => true]],
        ];
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            str_contains($r->url(), '/locations') => Http::response(['locations' => $this->locations]),
            default => Http::response([], 404),
        });
    }

    private function sync(): void
    {
        $this->inTenant($this->t['org'], fn () => app(BusinessProfileLocations::class)->sync($this->c->fresh()));
    }

    private function changes(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'business_location.changed_on_google')
            ->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($e) => $e->payload['after'])->all();
    }

    public function test_a_listing_google_stops_returning_is_not_listed_then_listed_again(): void
    {
        $this->sync();
        $south = $this->locations[1];
        $this->locations = [$this->locations[0]];
        $this->sync();

        $row = BusinessLocation::withoutGlobalScopes()->where('remote_name', 'locations/2')->first();
        $this->assertNotNull($row->not_listed_since);
        $this->assertSame([['listed' => false]], $this->changes());

        $this->sync();
        $this->assertCount(1, $this->changes(), 'still missing is not a second change');

        $this->locations[] = $south;
        $this->sync();
        $this->assertNull($row->fresh()->not_listed_since);
        $this->assertSame([['listed' => false], ['listed' => true]], $this->changes());
    }

    public function test_the_digest_sums_the_day_and_says_nothing_on_a_quiet_one(): void
    {
        $this->artisan('ezbizi:gbp-daily-digest')->assertSuccessful();
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'business_location.daily_digest')->count());

        $this->sync();
        $this->locations[0]['phoneNumbers']['primaryPhone'] = '555-0199';
        $this->sync();
        $this->inTenant($this->t['org'], function () {
            foreach ([5, 1] as $stars) {
                AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'review.received',
                    'payload' => ['after' => ['rating' => $stars]], 'occurred_at' => now(), 'available_at' => now()]);
            }
            // Older than a day: not in today's digest.
            AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'review.removed',
                'payload' => [], 'occurred_at' => now()->subDays(2), 'available_at' => now()]);
        });

        $this->artisan('ezbizi:gbp-daily-digest')->assertSuccessful();

        $digest = AutomationEvent::withoutGlobalScopes()->where('event_key', 'business_location.daily_digest')->sole()->payload['after'];
        $this->assertEquals(['changes' => 1, 'new_reviews' => 2, 'low_star' => 1, 'removed' => 0],
            array_intersect_key($digest, array_flip(['changes', 'new_reviews', 'low_star', 'removed'])));
        $this->assertStringContainsString('North - phone: 555-0101 -> 555-0199', $digest['summary']);
        $this->assertStringContainsString('2 new review(s), 1 of 2 stars or fewer.', $digest['summary']);
    }
}
