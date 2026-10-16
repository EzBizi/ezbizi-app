<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * UI/UX's findings, 2026-10-16: A-1's reviews already answered on Google read
 * as Unanswered,. (Publish stays as it is: Jose, 2026-10-16.)
 */
class InterimReviewRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private SocialConnection $c;

    private array $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant();
        $this->c = $this->connection(['interim' => 'a1leads']);
        $raw = fn (string $id, ?array $reply) => ['name' => "accounts/P/locations/1/reviews/{$id}", 'reviewer' => ['displayName' => 'Dana'],
            'starRating' => 'FIVE', 'comment' => 'Great.', 'createTime' => '2026-10-14T09:00:00Z', ...($reply ? ['reviewReply' => $reply] : [])];
        $this->reviews = [$raw('answered', ['comment' => 'Thank you, Dana!', 'updateTime' => '2026-10-14T12:00:00Z']), $raw('open', null)];
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            str_contains($r->url(), '/reviews') && $r->method() === 'GET' => Http::response(['reviews' => $this->reviews]),
            str_contains($r->url(), '/locations') => Http::response(['locations' => [['name' => 'accounts/P/locations/1']]]),
            default => Http::response([], 200),
        });
    }

    private function connection(array $extra): SocialConnection
    {
        return $this->inTenant($this->t['org'], function () use ($extra) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $this->t['org']->id, 'scope_type' => 'organization', 'scope_id' => $this->t['org']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value, 'remote_id' => 'P'.count($extra), 'display_name' => 'A-1']);
            $c->connect(['refresh_token' => 'rt', 'client_id' => 'theirs', 'client_secret' => 's', ...$extra]);
            $c->save();

            return $c;
        });
    }

    private function sync(): void
    {
        $this->inTenant($this->t['org'], fn () => app(BusinessProfileReviews::class)->sync($this->c->fresh()));
    }

    public function test_a_reply_already_on_google_counts_as_answered(): void
    {
        $this->sync();

        $this->signedInAs($this->t['admin'])->getJson('/api/v1/reviews')->assertOk()->assertJsonPath('meta.unanswered', 1);
        $reply = ReviewReply::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('Thank you, Dana!', $reply->body);
        $this->assertSame(ReviewReply::BY_GOOGLE, $reply->drafted_by);

        // Synced again: still one, not a second row.
        $this->sync();
        $this->assertSame(1, ReviewReply::withoutGlobalScopes()->count());
    }

    public function test_google_never_overwrites_a_draft_written_here(): void
    {
        $this->sync();
        $open = $this->inTenant($this->t['org'], fn () => Review::query()->where('remote_name', 'like', '%/open')->firstOrFail());
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/reviews/{$open->id}/reply", ['body' => 'Ours.'])->assertOk();

        $this->reviews[1]['reviewReply'] = ['comment' => 'Someone else on Google.'];
        $this->sync();

        $this->assertSame('Ours.', $open->reply()->withoutGlobalScopes()->first()->body);
    }

}
