<?php

namespace Tests\Feature\Reviews;

use App\Models\AutomationEvent;
use App\Models\Review;
use App\Models\SocialConnection;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A1 Leads takeover P0 (2026-10-16): a removed review is three complete lists
 * without it, each confirmed by a 404 on the review itself, two hours apart.
 * And a new review says how old it is, for a "low stars, recent" rule.
 */
class ReviewRemovalTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $c;

    /** @var list<string> the review ids Google lists right now */
    private array $listed = ['keep', 'gone'];

    /** What a direct GET of the missing review answers. */
    private int $direct = 404;

    private bool $undated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        $this->c = $this->inTenant($this->t['org'], function () {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => 'organization', 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value, 'remote_id' => 'P', 'display_name' => 'A-1']);
            $c->connect(['refresh_token' => 'rt', 'client_id' => 'theirs', 'client_secret' => 's']);
            $c->save();

            return $c;
        });
        $raw = fn (string $id) => ['name' => "accounts/P/locations/1/reviews/{$id}", 'reviewer' => ['displayName' => 'Dana'],
            'starRating' => 'ONE', 'comment' => 'Bad.', ...($this->undated && $id === 'undated' ? [] : ['createTime' => now()->subDays(3)->toIso8601String()])];
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/reviews') => Http::response(['reviews' => array_map($raw, $this->listed)]),
            str_contains($r->url(), '/reviews/') => Http::response([], $this->direct),
            str_contains($r->url(), '/locations') => Http::response(['locations' => [['name' => 'accounts/P/locations/1']]]),
            default => Http::response([], 404),
        });
    }

    private function sync(): void
    {
        $this->inTenant($this->t['org'], fn () => app(BusinessProfileReviews::class)->sync($this->c->fresh()));
    }

    private function gone(): Review
    {
        return Review::withoutGlobalScopes()->where('remote_name', 'like', '%/gone')->firstOrFail();
    }

    private function removedEvents(): int
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.removed')->count();
    }

    public function test_three_spaced_404s_remove_it_once(): void
    {
        $this->sync();
        $this->listed = ['keep'];

        $this->sync();
        $this->assertSame(1, $this->gone()->missing_checks);
        $this->sync();
        $this->assertSame(1, $this->gone()->missing_checks, 'a second look within two hours is not a second check');

        $this->travel(3)->hours();
        $this->sync();
        $this->travel(3)->hours();
        $this->sync();

        $this->assertNotNull($this->gone()->removed_at);
        $this->assertSame(1, $this->removedEvents());

        $this->travel(3)->hours();
        $this->sync();
        $this->assertSame(1, $this->removedEvents(), 'raised once');
        $this->assertSame(0, Review::withoutGlobalScopes()->where('remote_name', 'like', '%/keep')->first()->missing_checks);
    }

    /** Missing from the list but Google still answers for it: not removed, and the count starts over. */
    public function test_a_review_that_still_answers_is_not_removed(): void
    {
        $this->sync();
        $this->listed = ['keep'];
        $this->sync();
        $this->assertSame(1, $this->gone()->missing_checks);

        $this->direct = 200;
        $this->travel(3)->hours();
        $this->sync();
        $this->assertSame(0, $this->gone()->missing_checks);

        // An error from Google is no evidence either way.
        $this->direct = 503;
        foreach ([1, 2, 3] as $i) {
            $this->travel(3)->hours();
            $this->sync();
        }
        $this->assertNull($this->gone()->removed_at);
        $this->assertSame(0, $this->removedEvents());
    }

    public function test_a_new_review_says_how_old_it_is(): void
    {
        $this->sync();
        $this->listed = ['keep', 'gone', 'fresh'];
        $this->sync();

        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.received')->firstOrFail();
        $this->assertSame(3, $event->payload['after']['age_days']);
        $this->assertSame(1, $event->payload['after']['rating']);
    }

    /** A rule reads null as 0, so an undated review must still carry a number. */
    public function test_an_undated_review_still_has_an_age(): void
    {
        $this->sync();
        $this->listed = ['keep', 'gone', 'undated'];
        $this->undated = true;
        $this->sync();

        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.received')->firstOrFail();
        $this->assertIsInt($event->payload['after']['age_days']);
    }
}
