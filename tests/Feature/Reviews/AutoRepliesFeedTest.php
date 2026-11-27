<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\ReviewReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 2026-11-10: a feed of the reviews the AI answered or drafted, with our reply and the counts. */
class AutoRepliesFeedTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_lists_ai_replies_with_counts_and_filters(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $make = function (string $who, int $stars, ?string $by, string $status) use ($t) {
                $r = Review::create(['organization_id' => $t['org']->id, 'franchise_id' => $t['franchise']->id,
                    'remote_name' => 'accounts/1/locations/1/reviews/'.$who, 'location_name' => 'accounts/1/locations/1',
                    'reviewer_name' => $who, 'rating' => $stars, 'comment' => "From $who", 'reviewed_at' => now()->subDay()]);
                $by && ReviewReply::create(['organization_id' => $t['org']->id, 'review_id' => $r->id, 'body' => "Thanks $who",
                    'status' => $status, 'drafted_by' => $by]);
            };
            $make('Ann', 5, ReviewReply::BY_AUTOMATION, ReviewReply::STATUS_PUBLISHED);
            $make('Bob', 2, ReviewReply::BY_DRAFTING_JOB, ReviewReply::STATUS_DRAFT);
            $make('Cy', 4, ReviewReply::BY_AUTOMATION, ReviewReply::STATUS_FAILED);
            $make('Dee', 5, ReviewReply::BY_PERSON, ReviewReply::STATUS_PUBLISHED);   // a person's reply: not in the feed
            $make('Eve', 5, null, '');                                                // unanswered: not in the feed
        });

        $this->signedInAs($t['admin']);
        $all = $this->getJson('/api/v1/reviews/auto-replies')->assertOk();
        $this->assertSame(['published' => 1, 'draft' => 1, 'failed' => 1], $all->json('meta.counts'));
        $this->assertSame(['Ann', 'Bob', 'Cy'], collect($all->json('data'))->pluck('reviewer_name')->sort()->values()->all());
        $this->assertSame('Thanks Ann', collect($all->json('data'))->firstWhere('reviewer_name', 'Ann')['reply']['body']);

        $this->assertSame(['Bob'], collect($this->getJson('/api/v1/reviews/auto-replies?status=draft')->json('data'))->pluck('reviewer_name')->all());
        $this->assertSame(['Cy'], collect($this->getJson('/api/v1/reviews/auto-replies?stars=4')->json('data'))->pluck('reviewer_name')->all());
        $this->assertSame(['Ann'], collect($this->getJson('/api/v1/reviews/auto-replies?q=Ann')->json('data'))->pluck('reviewer_name')->all());
    }
}
