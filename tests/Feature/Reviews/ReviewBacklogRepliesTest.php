<?php

namespace Tests\Feature\Reviews;

use App\Models\BusinessLocation;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Services\Reviews\BusinessProfileReviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 2026-11-02: answer the unanswered reviews, a few a day, every reply different. */
class ReviewBacklogRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_answers_unanswered_reviews_up_to_the_limit_and_never_repeats_a_reply(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $t = $this->provisionTenant('Alpha');
        [$a, $b, $c] = $this->inTenant($t['org'], function () use ($t) {
            (new BusinessLocation)->forceFill(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'remote_name' => 'accounts/1/locations/9', 'title' => 'L', 'auto_reply' => true])->save();
            $conn = \App\Models\SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization',
                'scope_id' => $t['org']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/1', 'display_name' => 'GBP']);
            $conn->connect(['refresh_token' => 'rt']);
            $conn->save();
            $gone = \App\Models\SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization',
                'scope_id' => $t['org']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/2', 'display_name' => 'Old']);
            $gone->forceFill(['status' => 'disconnected'])->save();
            // Its profile is disconnected: skipped quietly, never sent to Google (desk, 2026-11-09).
            Review::create(['organization_id' => $t['org']->id, 'connection_id' => $gone->id, 'remote_name' => 'accounts/2/locations/9/reviews/x',
                'location_name' => 'accounts/2/locations/9', 'reviewer_name' => 'Ann', 'rating' => 5, 'reviewed_at' => now()]);
            $make = fn (int $i) => Review::create(['organization_id' => $t['org']->id, 'connection_id' => $conn->id, 'remote_name' => 'accounts/1/locations/9/reviews/'.$i,
                'location_name' => 'accounts/1/locations/9', 'reviewer_name' => 'Dana', 'rating' => 5, 'reviewed_at' => now()->subDays($i)]);

            Review::create(['organization_id' => $t['org']->id, 'connection_id' => $conn->id, 'remote_name' => 'accounts/1/locations/9/reviews/bad',
                'location_name' => 'accounts/1/locations/9', 'reviewer_name' => 'Sam', 'rating' => 2, 'reviewed_at' => now()]);

            return [$make(1), $make(2), $make(3)];
        });
        $this->mock(BusinessProfileReviews::class)->shouldReceive('publish')->times(1);
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Thank you, Dana!']]))]);

        $this->artisan('ezbizi:review-backlog', ['--limit' => 2])->assertSuccessful();

        $published = ReviewReply::withoutGlobalScopes()->where('status', ReviewReply::STATUS_PUBLISHED)->pluck('review_id')->all();
        $this->assertSame([$a->id], $published, 'newest first; the same words twice are never published');
        $bad = Review::withoutGlobalScopes()->where('remote_name', 'like', '%/bad')->first();
        $this->assertSame(ReviewReply::STATUS_DRAFT, ReviewReply::withoutGlobalScopes()->where('review_id', $bad->id)->value('status'),
            'a 2-star review gets a draft for the dispatchers, never a published reply');
    }

    /** Health sweep 11-26: a removed review is marked and an unclaimed listing parked, not errored every day. */
    public function test_removed_reviews_and_unclaimed_listings_are_recorded_not_errored(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $t = $this->provisionTenant('Alpha');
        [$gone, $unclaimed] = $this->inTenant($t['org'], function () use ($t) {
            (new BusinessLocation)->forceFill(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'remote_name' => 'accounts/1/locations/9', 'title' => 'L', 'auto_reply' => true])->save();
            $conn = \App\Models\SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => 'organization',
                'scope_id' => $t['org']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/1', 'display_name' => 'GBP']);
            $conn->connect(['refresh_token' => 'rt']);
            $conn->save();
            $make = fn (string $k, int $d) => Review::create(['organization_id' => $t['org']->id, 'connection_id' => $conn->id, 'remote_name' => 'accounts/1/locations/9/reviews/'.$k,
                'location_name' => 'accounts/1/locations/9', 'reviewer_name' => 'Dana '.$k, 'rating' => 5, 'reviewed_at' => now()->subDays($d)]);

            return [$make('gone', 1), $make('unclaimed', 2)];
        });
        $google = $this->mock(BusinessProfileReviews::class);
        $google->shouldReceive('publish')->andReturnUsing(fn (ReviewReply $r) => throw new \RuntimeException(
            $r->review_id === $gone->id ? 'Google refused the reply (HTTP 404: Requested entity was not found.)' : 'Google refused the reply (HTTP 400: not supported for unclaimed locations)'));
        $google->shouldReceive('reviewIsGone')->andReturn(true);
        $n = 0;
        Process::fake(['*' => function () use (&$n) { return Process::result(json_encode(['structuredOutput' => ['reply' => 'Thanks '.(++$n)]])); }]);

        $this->artisan('ezbizi:review-backlog', ['--limit' => 5])->assertSuccessful()->doesntExpectOutputToContain('refused');

        $this->assertNotNull(Review::withoutGlobalScopes()->find($gone->id)->removed_at);
        $this->assertStringContainsString('unclaimed', (string) ReviewReply::withoutGlobalScopes()->where('review_id', $unclaimed->id)->value('failure_reason'));
    }
}
