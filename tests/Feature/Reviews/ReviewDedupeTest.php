<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\ReviewNote;
use App\Models\ReviewReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk 2026-11-20: a listing reached through its own and a manager account stored every review twice. */
class ReviewDedupeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_review_stored_under_two_accounts_folds_into_one_keeping_reply_and_notes(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$a, $b] = $this->inTenant($t['org'], function () use ($t) {
            $make = fn (string $acct) => Review::create(['organization_id' => $t['org']->id, 'remote_name' => "accounts/$acct/locations/9/reviews/R1",
                'location_name' => "accounts/$acct/locations/9", 'reviewer_name' => 'Dana', 'rating' => 5, 'reviewed_at' => now()]);
            [$a, $b] = [$make('own'), $make('manager')];
            ReviewReply::create(['organization_id' => $t['org']->id, 'review_id' => $b->id, 'body' => 'Thanks Dana',
                'status' => ReviewReply::STATUS_PUBLISHED, 'drafted_by' => ReviewReply::BY_PERSON]);
            ReviewNote::create(['organization_id' => $t['org']->id, 'review_id' => $a->id, 'user_id' => $t['admin']->id, 'body' => 'Called her']);
            Review::create(['organization_id' => $t['org']->id, 'remote_name' => 'accounts/own/locations/9/reviews/R2',
                'location_name' => 'accounts/own/locations/9', 'reviewer_name' => 'Lee', 'rating' => 4, 'reviewed_at' => now()]);

            return [$a, $b];
        });
        $this->assertSame('R1', $a->fresh()->review_key);

        $this->artisan('ezbizi:reviews-dedupe')->expectsOutputToContain('1 reviews stored more than once, 1 extra rows.')->assertSuccessful();

        $left = Review::withoutGlobalScopes()->where('review_key', 'R1')->get();
        $this->assertCount(1, $left);
        $this->assertSame($b->id, $left->first()->id, 'the one with the published reply is kept');
        $this->assertSame(1, ReviewNote::withoutGlobalScopes()->where('review_id', $b->id)->count(), 'the note moved over');
        $this->assertSame(2, Review::withoutGlobalScopes()->count());

        $this->signedInAs($t['admin']);
        $this->assertSame(2, $this->getJson('/api/v1/reviews')->assertOk()->json('meta.total'));
    }
}
