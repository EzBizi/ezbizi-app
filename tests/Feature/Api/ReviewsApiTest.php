<?php

namespace Tests\Feature\Api;

use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reviews, and the split between drafting a reply and publishing one.
 *
 * The cases worth having are the ones about that split. A draft is private; a
 * published reply is a permanent public statement in the business's name, and
 * the whole design rests on those needing different permissions and different
 * requests. If that ever collapses into one, these fail.
 */
class ReviewsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/reviews';

    /** @param array{org: Organization} $t */
    private function review(array $t, array $attributes = []): Review
    {
        return $this->inTenant($t['org'], fn () => Review::create([
            'organization_id' => $t['org']->id,
            'remote_name'     => 'accounts/1/locations/2/reviews/'.bin2hex(random_bytes(6)),
            'location_name'   => 'accounts/1/locations/2',
            'reviewer_name'   => 'Dana Whitfield',
            'rating'          => 2,
            'comment'         => 'Turned up late and left soot on the carpet.',
            'reviewed_at'     => now()->subDay(),
            ...$attributes,
        ]));
    }

    public function test_an_organization_level_review_is_listed_and_counted_for_a_branch_user(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->review($t);   // company_id and franchise_id both null

        $branch = $this->userWithRole($t, 'Branch', ['reviews.view'], onlyAt: $t['franchise']);

        $this->signedInAs($branch)
            ->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unanswered', 1);
    }

    /**
     * One failing profile must not turn an honest empty result into an error.
     *
     * The same fetched===0 conflation LeadsController::sync was corrected for.
     * Zero reviews is the right answer for a profile nobody has reviewed; only
     * when EVERY connection failed is the sync a failure - and then the reason
     * has to reach the screen as a top-level message.
     */
    public function test_one_failing_profile_does_not_turn_an_empty_result_into_an_error(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connectedProfile($t, 'acct-good');
        $this->connectedProfile($t, 'acct-bad');

        $this->fakeGoogle([
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/acct-good/locations*' => Http::response(['locations' => []], 200),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/acct-bad/locations*'  => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/reviews/sync')
            ->assertOk()
            ->assertJsonPath('data.fetched', 0)
            ->assertJsonCount(1, 'data.failures');
    }

    public function test_every_profile_failing_is_a_failure_with_its_reason_on_top(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->connectedProfile($t, 'acct-bad');

        $this->fakeGoogle([
            'mybusiness.googleapis.com/v4/*' => Http::response(['error' => ['status' => 'PERMISSION_DENIED']], 403),
        ]);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/reviews/sync')
            ->assertStatus(502)
            ->assertJsonPath('message', fn ($m) => is_string($m) && $m !== '');
    }

    private function connectedProfile(array $t, string $account): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $account) {
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

            return $c;
        });
    }

    /** @param array<string, mixed> $routes */
    private function fakeGoogle(array $routes): void
    {
        config([
            'services.business_profile.client_id'     => 'client.apps.googleusercontent.com',
            'services.business_profile.client_secret' => 'client-secret',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fresh', 'expires_in' => 3600], 200),
            ...$routes,
        ]);
    }

    public function test_a_review_is_listed_with_its_rating_and_words(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->review($t);

        $response = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk();

        $this->assertSame('Dana Whitfield', $response->json('data.0.reviewer_name'));
        $this->assertSame(2, $response->json('data.0.rating'));
        $this->assertNull($response->json('data.0.reply'));
    }

    public function test_another_organizations_reviews_are_never_listed(): void
    {
        $mine   = $this->provisionTenant('Mine');
        $theirs = $this->provisionTenant('Theirs');

        $this->review($theirs, ['reviewer_name' => 'Not Yours']);

        $this->signedInAs($mine['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_a_review_with_no_stars_stays_without_stars(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->review($t, ['rating' => null]);

        $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            // Not 0. A review nobody rated is not a nought-star review, and
            // defaulting it would put an opinion in somebody's mouth.
            ->assertJsonPath('data.0.rating', null);
    }

    public function test_a_draft_can_be_written_and_is_not_published(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $response = $this->signedInAs($t['admin'])
            ->putJson(self::ENDPOINT."/{$review->id}/reply", ['body' => 'We are sorry about that.'])
            ->assertOk();

        $this->assertSame('We are sorry about that.', $response->json('data.reply.body'));
        $this->assertSame(ReviewReply::STATUS_DRAFT, $response->json('data.reply.status'));
        $this->assertNull($response->json('data.reply.published_at'));
    }

    public function test_an_empty_reply_is_refused(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $this->signedInAs($t['admin'])
            ->putJson(self::ENDPOINT."/{$review->id}/reply", ['body' => '  '])
            ->assertStatus(422);
    }

    /**
     * The case the whole module is arranged around.
     *
     * Somebody who may draft must not be able to publish. If `reviews.edit`
     * ever starts carrying publication, this is what says so.
     */
    public function test_drafting_does_not_carry_the_right_to_publish(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $writer = $this->userWithRole($t, 'Writer', ['reviews.view', 'reviews.edit']);

        $this->signedInAs($writer)
            ->putJson(self::ENDPOINT."/{$review->id}/reply", ['body' => 'Sorry about that.'])
            ->assertOk();

        $this->signedInAs($writer)
            ->postJson(self::ENDPOINT."/{$review->id}/reply/publish")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'publish a reply')
                    && ! str_contains($m, 'reviews.publish'),
            );

        // Resolved INSIDE the tenant closure: the relation is lazy, and reading
        // it afterwards is a read with no organization in context.
        $this->assertSame(
            ReviewReply::STATUS_DRAFT,
            $this->inTenant($t['org'], fn () => Review::find($review->id)->reply->status),
        );
    }

    public function test_publishing_with_nothing_drafted_says_so(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT."/{$review->id}/reply/publish")
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'nothing drafted'),
            );
    }

    public function test_a_draft_can_be_discarded(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $this->signedInAs($t['admin'])
            ->putJson(self::ENDPOINT."/{$review->id}/reply", ['body' => 'Draft.'])
            ->assertOk();

        $this->signedInAs($t['admin'])
            ->deleteJson(self::ENDPOINT."/{$review->id}/reply")
            ->assertOk()
            ->assertJsonPath('data.reply', null);
    }

    /**
     * A published reply is already public; editing the stored copy without
     * republishing would have EzBizi disagree with Google about what the
     * business said, and the screen would be the one lying.
     */
    public function test_a_published_reply_cannot_be_quietly_rewritten(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->review($t);

        $this->inTenant($t['org'], fn () => ReviewReply::create([
            'organization_id' => $t['org']->id,
            'review_id'       => $review->id,
            'body'            => 'What we actually said in public.',
            'status'          => ReviewReply::STATUS_PUBLISHED,
            'published_at'    => now()->subHour(),
        ]));

        $this->signedInAs($t['admin'])
            ->putJson(self::ENDPOINT."/{$review->id}/reply", ['body' => 'Something else.'])
            ->assertStatus(409);

        $this->assertSame(
            'What we actually said in public.',
            $this->inTenant($t['org'], fn () => Review::find($review->id)->reply->body),
        );
    }

    public function test_the_unanswered_filter_hides_answered_reviews(): void
    {
        $t        = $this->provisionTenant('A1-Group');
        $answered = $this->review($t, ['reviewer_name' => 'Answered']);
        $this->review($t, ['reviewer_name' => 'Waiting']);

        $this->inTenant($t['org'], fn () => ReviewReply::create([
            'organization_id' => $t['org']->id,
            'review_id'       => $answered->id,
            'body'            => 'Thanks.',
            'status'          => ReviewReply::STATUS_PUBLISHED,
            'published_at'    => now(),
        ]));

        $response = $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT.'?answered=no')
            ->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('Waiting', $response->json('data.0.reviewer_name'));
        $this->assertSame(1, $response->json('meta.unanswered'));
    }

    public function test_there_is_no_way_to_invent_a_review(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT, ['comment' => 'We are wonderful'])
            ->assertStatus(405);
    }

    public function test_the_list_is_refused_without_the_reviews_tab(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $this->review($t);

        $this->signedInAs($viewer)
            ->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'see this review')
                    && ! str_contains($m, 'reviews.view'),
            );
    }

    public function test_syncing_with_nothing_connected_says_so_rather_than_failing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->postJson(self::ENDPOINT.'/sync')
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'No Business Profile is connected'),
            );
    }

    public function test_the_list_reports_whether_a_profile_is_connected(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'provider'        => SocialProvider::GoogleBusinessProfile->value,
            'remote_id'       => '1234567890',
            'display_name'    => 'A-1 Chimney',
        ]));

        $this->signedInAs($t['admin'])
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('meta.connections.0.provider', 'google_business_profile');
    }

    /** The search bar: `q` narrows by reviewer or comment; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant('A1-Chimney');
        $this->review($t);
        $this->review($t, ['reviewer_name' => 'Marco Ruiz', 'comment' => 'Swept clean, on time.', 'rating' => 5]);

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=marco')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reviewer_name', 'Marco Ruiz');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=soot')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reviewer_name', 'Dana Whitfield');
        // A LIKE wildcard is a literal character, not "match anything".
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?q=%20%20')->assertOk()->assertJsonCount(2, 'data');
    }
}
