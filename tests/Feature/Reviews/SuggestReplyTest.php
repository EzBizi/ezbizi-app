<?php

namespace Tests\Feature\Reviews;

use App\Models\Review;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12, AI item 11: AI drafts, a person edits, saves and publishes. */
class SuggestReplyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_ai_suggests_words_and_saves_nothing(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $t = $this->provisionTenant('A1-Group');
        $review = $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => SocialProvider::GoogleBusinessProfile->value,
                'remote_id' => 'accounts/1', 'display_name' => 'A-1 Chimney',
            ]);

            return Review::create([
                'organization_id' => $t['org']->id, 'connection_id' => $connection->id,
                'remote_name' => 'accounts/1/locations/2/reviews/x', 'location_name' => 'accounts/1/locations/2',
                'reviewer_name' => 'Dana Whitfield', 'rating' => 5, 'comment' => 'Great sweep, on time.',
                'reviewed_at' => now()->subDay(),
            ]);
        });
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Thank you, Dana!']]))]);

        $this->signedInAs($t['admin'])->postJson("/api/v1/reviews/{$review->id}/reply/suggest")
            ->assertOk()->assertJsonPath('data.body', 'Thank you, Dana!');

        $this->assertNull($this->inTenant($t['org'], fn () => $review->fresh()->reply), 'a suggestion is not a draft until a person saves it');
        Process::assertRan(fn ($p) => in_array('--json-schema', (array) $p->command, true));
    }

    /** A1 Leads takeover #22: draft several at once, saved as drafts, never posted. */
    public function test_batch_drafting_saves_drafts_and_monthly_stats_cover_a_year(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        $t = $this->provisionTenant('A1-Group');
        $ids = $this->inTenant($t['org'], fn () => collect([5, 4])->map(fn ($r) => Review::create([
            'organization_id' => $t['org']->id, 'remote_name' => 'accounts/1/locations/2/reviews/'.$r,
            'location_name' => 'accounts/1/locations/2', 'reviewer_name' => 'Dana', 'rating' => $r,
            'comment' => 'Good.', 'reviewed_at' => now()->subDay(),
        ])->id)->all());
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Thank you!']]))]);
        $as = $this->signedInAs($t['admin']);

        $data = $as->postJson('/api/v1/reviews/replies/draft-batch', ['ids' => $ids])->assertOk()->json('data');
        $this->assertSame([true, true], array_column($data, 'ok'));
        $this->assertSame(2, \App\Models\ReviewReply::withoutGlobalScopes()->where('status', 'draft')->where('drafted_by', 'drafting_job')->count());

        $monthly = $as->getJson('/api/v1/reviews')->assertOk()->json('meta.stats.monthly');
        $this->assertCount(12, $monthly);
        $this->assertSame(2, end($monthly)['count'] + (now()->subDay()->month !== now()->month ? $monthly[10]['count'] : 0));
    }
}
