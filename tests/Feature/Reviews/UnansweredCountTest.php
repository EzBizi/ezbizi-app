<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\ReviewReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Adeline, approved by Jose 2026-11-19: the Unanswered count follows the other filters; low means 1 to 3 stars. */
class UnansweredCountTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_unanswered_count_follows_the_other_filters(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $make = function (int $stars, int $daysAgo, bool $answered) use ($t) {
                $r = Review::create(['organization_id' => $t['org']->id, 'remote_name' => 'accounts/1/locations/1/reviews/'.uniqid(),
                    'location_name' => 'accounts/1/locations/1', 'reviewer_name' => 'X', 'rating' => $stars, 'reviewed_at' => now()->subDays($daysAgo)]);
                $answered && ReviewReply::create(['organization_id' => $t['org']->id, 'review_id' => $r->id, 'body' => 'Thanks',
                    'status' => ReviewReply::STATUS_PUBLISHED, 'drafted_by' => ReviewReply::BY_PERSON]);
            };
            $make(1, 1, false);   // counted with every filter
            $make(3, 2, false);   // 3 stars is "low" now
            $make(3, 30, false);  // low, but not this week
            $make(5, 1, false);   // this week, not low
            $make(2, 1, true);    // answered
        });
        $this->signedInAs($t['admin']);

        $this->assertSame(4, $this->getJson('/api/v1/reviews')->assertOk()->json('meta.unanswered'));
        $this->assertSame(3, $this->getJson('/api/v1/reviews?rating=negative')->json('meta.unanswered'));
        $week = now()->subDays(7)->toDateString();
        $this->assertSame(2, $this->getJson("/api/v1/reviews?rating=negative&answered=no&from=$week")->json('meta.unanswered'));
    }
}
