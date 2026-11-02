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
            $make = fn (int $i) => Review::create(['organization_id' => $t['org']->id, 'remote_name' => 'accounts/1/locations/9/reviews/'.$i,
                'location_name' => 'accounts/1/locations/9', 'reviewer_name' => 'Dana', 'rating' => 5, 'reviewed_at' => now()->subDays($i)]);

            return [$make(1), $make(2), $make(3)];
        });
        $this->mock(BusinessProfileReviews::class)->shouldReceive('publish')->times(1);
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Thank you, Dana!']]))]);

        $this->artisan('ezbizi:review-backlog', ['--limit' => 2])->assertSuccessful();

        $published = ReviewReply::withoutGlobalScopes()->where('status', ReviewReply::STATUS_PUBLISHED)->pluck('review_id')->all();
        $this->assertSame([$a->id], $published, 'newest first; the same words twice are never published');
    }
}
