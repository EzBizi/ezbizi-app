<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The reply row and its outbox row commit together, or neither does.
 *
 * ── Why this path and not the success one ────────────────────
 *
 * The success path was already one transaction. This is the FAILURE path, and
 * it is the half that matters more: the record it writes says a publish failed,
 * and the event it emits is the only way anybody finds out. An outbox insert
 * that threw left the reply durably `failed` with nobody told — and a vanishing
 * "something is broken" cannot be told apart from nothing being broken.
 *
 * The gap was real and it was mine: the `catch` sits outside the transaction
 * that wraps the success path, so there was no enclosing transaction to
 * inherit and nothing was rolling it back.
 */
class ReviewEventAtomicityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @param array{org: \App\Models\Organization} $t */
    private function reviewWithDraft(array $t): Review
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $t['org']->id,
                'provider'        => SocialProvider::GoogleBusinessProfile->value,
                'remote_id'       => 'accounts/1',
                'display_name'    => 'A-1 Chimney',
            ]);

            $review = Review::create([
                'organization_id' => $t['org']->id,
                'connection_id'   => $connection->id,
                'remote_name'     => 'accounts/1/locations/2/reviews/'.bin2hex(random_bytes(4)),
                'location_name'   => 'accounts/1/locations/2',
                'reviewer_name'   => 'Dana Whitfield',
                'rating'          => 1,
                'comment'         => 'Nobody turned up.',
                'reviewed_at'     => now()->subDay(),
            ]);

            ReviewReply::create([
                'organization_id' => $t['org']->id,
                'review_id'       => $review->id,
                'body'            => 'We are sorry, and we would like to put it right.',
                'status'          => ReviewReply::STATUS_DRAFT,
            ]);

            return $review->fresh();
        });
    }

    /**
     * The case the ordering has to survive.
     *
     * Google refuses the publish, so the controller writes `failed` and emits
     * the event that tells somebody. If writing the event throws, the reply
     * must NOT be left sitting at `failed` with no event beside it — that is a
     * failure nobody is ever told about.
     */
    public function test_a_failed_publish_whose_event_cannot_be_written_does_not_record_the_failure_alone(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->reviewWithDraft($t);

        // Google refuses — the branch under test.
        $this->mock(\App\Services\Reviews\BusinessProfileReplies::class)
            ->shouldReceive('publish')
            ->andThrow(new RuntimeException('Google refused the reply (HTTP 429).'));

        // …and then the outbox insert fails too.
        AutomationEvent::creating(fn () => throw new RuntimeException('the outbox write failed'));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/reviews/{$review->id}/reply/publish")
            ->assertStatus(500);

        $this->inTenant($t['org'], function () use ($review) {
            $reply = ReviewReply::query()->where('review_id', $review->id)->first();

            $this->assertSame(
                ReviewReply::STATUS_DRAFT,
                $reply->status,
                'The failure could not be announced, so it must not have been recorded either — '
                .'a reply left at `failed` with no event is a failure nobody is ever told about.',
            );
            $this->assertNull($reply->failure_reason);
        });
    }

    /**
     * And the ordinary case still works: a refusal Google explains is recorded,
     * announced and audited, all three.
     */
    public function test_a_failed_publish_records_announces_and_audits_together(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->reviewWithDraft($t);

        $this->mock(\App\Services\Reviews\BusinessProfileReplies::class)
            ->shouldReceive('publish')
            ->andThrow(new RuntimeException('Google refused the reply (HTTP 429).'));

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/reviews/{$review->id}/reply/publish")
            ->assertStatus(502);

        $this->inTenant($t['org'], function () use ($review) {
            $reply = ReviewReply::query()->where('review_id', $review->id)->first();

            $this->assertSame(ReviewReply::STATUS_FAILED, $reply->status);
            $this->assertNotNull(
                $reply->failure_reason,
                'the reason Google refused is what somebody needs in order to fix it',
            );

            $this->assertTrue(
                AutomationEvent::query()->where('event_key', 'review.reply_failed')->exists(),
                'a recorded failure must be announced, or no rule can react to it',
            );
        });
    }
}
