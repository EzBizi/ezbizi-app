<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\YouTubeOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Every comment on every connected account in one inbox: Facebook Page
 * posts, Instagram through the Page, YouTube videos and X replies. Each row
 * names the actions it supports; a missing permission removes the action.
 */
class SocialCommentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.youtube.client_id' => 'yt-client', 'services.youtube.client_secret' => 'yt-secret']);
    }

    /** @param  array<string, mixed>  $credentials */
    private function connection(array $t, string $provider, string $remoteId, array $credentials, string $name, string $scopeType = 'organization', ?string $scopeId = null): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId, $credentials, $name, $scopeType, $scopeId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId ?? $t['org']->id,
                'provider' => $provider,
                'remote_id' => $remoteId,
                'display_name' => $name,
            ]);
            $row->connect($credentials);
            $row->save();

            return $row;
        });
    }

    private function facebook(array $t, array $scopes = ['pages_read_user_content', 'pages_manage_engagement']): SocialConnection
    {
        return $this->connection($t, 'facebook_page', 'page-1', ['access_token' => 'page-token', 'scopes' => $scopes], 'EzBizi Page');
    }

    private function youtube(array $t, array $scopes = [YouTubeOAuth::SCOPE_UPLOAD, YouTubeOAuth::SCOPE_MANAGE]): SocialConnection
    {
        return $this->connection($t, 'youtube', 'chan-1', ['refresh_token' => 'yt-refresh', 'scopes' => $scopes], 'EzBizi Channel');
    }

    private function x(array $t, array $scopes = ['tweet.read', 'tweet.write', 'users.read', 'offline.access']): SocialConnection
    {
        return $this->connection($t, 'x', 'x-user-1', [
            'access_token' => 'x-token', 'refresh_token' => 'x-refresh',
            'expires_at' => now()->addHour()->toIso8601String(), 'scopes' => $scopes,
        ], '@ezbizi');
    }

    /** @param  array<string, mixed>  $extra */
    private function fakeNetworks(array $extra = []): void
    {
        Http::fake($extra + [
            // Facebook Page posts with their comments, one call.
            'graph.facebook.com/v21.0/page-1/posts*' => Http::response(['data' => [
                ['id' => 'p-1', 'message' => 'Spring sweep', 'permalink_url' => 'https://facebook.com/p-1', 'comments' => ['data' => [
                    ['id' => 'fb-c1', 'message' => 'Price for a sweep?', 'from' => ['name' => 'Ann'], 'created_time' => '2026-11-14T10:00:00+0000', 'is_hidden' => false,
                        'comments' => ['data' => [['id' => 'fb-r1', 'message' => 'DM us', 'from' => ['name' => 'EzBizi'], 'created_time' => '2026-11-14T11:00:00+0000']]]],
                ]]],
            ]]),
            'graph.facebook.com/v21.0/fb-c1/comments' => Http::response(['id' => 'fb-r2']),
            'graph.facebook.com/v21.0/fb-c1' => Http::response(['success' => true]),
            // Instagram through the same Page.
            'graph.facebook.com/v21.0/page-1?*' => Http::response(['instagram_business_account' => ['id' => 'ig-9', 'username' => 'ezbizi']]),
            'graph.facebook.com/v21.0/ig-9/media*' => Http::response(['data' => [['id' => 'm-1', 'caption' => 'Chimney', 'comments_count' => 1]]]),
            'graph.facebook.com/v21.0/m-1/comments*' => Http::response(['data' => [
                ['id' => 'ig-c1', 'text' => 'Tampa?', 'username' => 'homeowner', 'timestamp' => '2026-11-13T09:00:00+0000', 'hidden' => false],
            ]]),
            // YouTube.
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'yt-access', 'expires_in' => 3600]),
            'www.googleapis.com/youtube/v3/commentThreads*' => Http::response(['items' => [
                ['id' => 'yt-t1', 'snippet' => ['videoId' => 'vid-1', 'topLevelComment' => ['id' => 'yt-c1', 'snippet' => [
                    'textOriginal' => 'Great video', 'authorDisplayName' => 'Bob', 'publishedAt' => '2026-11-15T08:00:00Z', 'moderationStatus' => 'published',
                ]]], 'replies' => ['comments' => [['id' => 'yt-r1', 'snippet' => ['textOriginal' => 'Thanks!', 'authorDisplayName' => 'EzBizi', 'publishedAt' => '2026-11-15T09:00:00Z']]]]],
            ]]),
            'www.googleapis.com/youtube/v3/comments/setModerationStatus*' => Http::response(null, 204),
            'www.googleapis.com/youtube/v3/comments?*' => Http::response(['id' => 'yt-r2']),
            // X.
            'api.x.com/2/users/x-user-1/mentions*' => Http::response([
                'data' => [['id' => 'x-1', 'text' => '@ezbizi do you do Clearwater?', 'author_id' => 'u-7', 'created_at' => '2026-11-16T07:00:00.000Z',
                    'conversation_id' => 'x-0', 'referenced_tweets' => [['type' => 'replied_to', 'id' => 'x-0']]]],
                'includes' => ['users' => [['id' => 'u-7', 'username' => 'carla']]],
            ]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-2']]),
        ]);
    }

    private function row(array $rows, string $network): array
    {
        return collect($rows)->firstWhere('network', $network) ?? $this->fail("No {$network} row.");
    }

    public function test_every_network_is_merged_newest_first_with_its_actions(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->facebook($t);
        $this->youtube($t);
        $this->x($t);
        $this->fakeNetworks();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk()
            ->assertJsonCount(4, 'sources')
            ->json('data');

        $this->assertSame(['x', 'youtube', 'facebook', 'instagram'], array_column($rows, 'network'));
        $this->assertSame(['reply', 'hide'], $this->row($rows, 'facebook')['actions']);
        $this->assertSame('DM us', $this->row($rows, 'facebook')['replies'][0]['text']);
        $this->assertSame(['reply', 'hide'], $this->row($rows, 'instagram')['actions']);
        $this->assertSame(['reply', 'hide'], $this->row($rows, 'youtube')['actions']);
        $this->assertSame('https://www.youtube.com/watch?v=vid-1', $this->row($rows, 'youtube')['post']['permalink']);
        $this->assertSame('carla', $this->row($rows, 'x')['author']);
        // X never granted tweet.moderate.write: no Hide, and the reason is on the row.
        $this->assertSame(['reply'], $this->row($rows, 'x')['actions']);
        $this->assertSame('X needs moderation permission to hide replies.', $this->row($rows, 'x')['unavailable']['hide']);
    }

    public function test_the_account_filter_reads_one_connection(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->facebook($t);
        $yt = $this->youtube($t);
        $this->fakeNetworks();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments?connection='.$yt->id)->assertOk()->json('data');

        $this->assertSame(['youtube'], array_values(array_unique(array_column($rows, 'network'))));
    }

    public function test_one_network_failing_leaves_the_others_and_a_note(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->facebook($t);
        $this->youtube($t);
        $this->x($t);
        $this->fakeNetworks(['api.x.com/2/users/x-user-1/mentions*' => Http::response(['detail' => 'Too Many Requests'], 429)]);

        $response = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk();

        $this->assertCount(3, $response->json('data'));
        $x = collect($response->json('sources'))->firstWhere('network', 'x');
        $this->assertSame('Too Many Requests', $x['error']);
        $this->assertNull(collect($response->json('sources'))->firstWhere('network', 'youtube')['error']);
    }

    public function test_facebook_reply_and_hide_reach_the_graph_and_are_audited(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->facebook($t);
        $this->fakeNetworks();

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/replies", ['message' => 'From $150.'])
            ->assertOk()->assertJsonPath('data.id', 'fb-r2');
        $this->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/hide", ['hide' => true])
            ->assertOk()->assertJsonPath('data.hidden', true);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/fb-c1/comments') && $r['message'] === 'From $150.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/fb-c1') && $r['is_hidden'] === 'true');
        $this->assertDatabaseHas('audit_events', ['action' => 'social.comment_replied']);
        $this->assertDatabaseHas('audit_events', ['action' => 'social.comment_hidden']);
    }

    public function test_facebook_without_manage_engagement_offers_no_reply_or_hide(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->facebook($t, ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']);
        $this->fakeNetworks();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk()->json('data');
        $this->assertSame([], $this->row($rows, 'facebook')['actions']);
        $this->assertStringContainsString('manage-engagement', $this->row($rows, 'facebook')['unavailable']['reply']);

        $this->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/replies", ['message' => 'Hi'])->assertUnprocessable();
        $this->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/hide", ['hide' => true])->assertUnprocessable();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_youtube_reply_and_hold_for_review(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $channel = $this->youtube($t);
        $this->fakeNetworks();

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/social/connections/{$channel->id}/comments/youtube/yt-c1/replies", ['message' => 'Glad it helped'])
            ->assertOk()->assertJsonPath('data.id', 'yt-r2');
        $this->postJson("/api/v1/social/connections/{$channel->id}/comments/youtube/yt-c1/hide", ['hide' => true])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/youtube/v3/comments?part=snippet')
            && $r['snippet']['parentId'] === 'yt-c1' && $r['snippet']['textOriginal'] === 'Glad it helped');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'setModerationStatus') && str_contains($r->url(), 'id=yt-c1')
            && str_contains($r->url(), 'moderationStatus=heldForReview'));
        $this->assertDatabaseHas('audit_events', ['action' => 'social.comment_hidden']);
    }

    public function test_youtube_without_force_ssl_reads_only(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $channel = $this->youtube($t, [YouTubeOAuth::SCOPE_UPLOAD, YouTubeOAuth::SCOPE_READONLY]);
        $this->fakeNetworks();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk()->json('data');
        $this->assertSame([], $this->row($rows, 'youtube')['actions']);

        $this->postJson("/api/v1/social/connections/{$channel->id}/comments/youtube/yt-c1/hide", ['hide' => true])->assertUnprocessable();
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setModerationStatus'));
    }

    public function test_x_reply_posts_a_tweet_in_reply_and_hide_is_refused_without_moderation(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $account = $this->x($t);
        $this->fakeNetworks();

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/social/connections/{$account->id}/comments/x/x-1/replies", ['message' => 'Yes, Clearwater too.'])
            ->assertOk()->assertJsonPath('data.id', 'x-2');
        $this->postJson("/api/v1/social/connections/{$account->id}/comments/x/x-1/hide", ['hide' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'X needs moderation permission to hide replies.');

        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets' && $r['reply']['in_reply_to_tweet_id'] === 'x-1');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/hidden'));
        $this->assertDatabaseHas('audit_events', ['action' => 'social.comment_replied']);
    }

    public function test_x_with_moderation_hides_a_reply(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $account = $this->x($t, ['tweet.read', 'tweet.write', 'users.read', 'tweet.moderate.write']);
        $this->fakeNetworks(['api.x.com/2/tweets/x-1/hidden' => Http::response(['data' => ['hidden' => true]])]);

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk()->json('data');
        $this->assertSame(['reply', 'hide'], $this->row($rows, 'x')['actions']);

        $this->postJson("/api/v1/social/connections/{$account->id}/comments/x/x-1/hide", ['hide' => true])->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/2/tweets/x-1/hidden') && $r['hidden'] === true);
    }

    public function test_no_network_offers_delete_and_the_route_refuses_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->facebook($t);
        $this->fakeNetworks();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/comments')->assertOk()->json('data');
        foreach ($rows as $row) {
            $this->assertNotContains('delete', $row['actions']);
        }
        $this->deleteJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1")->assertUnprocessable();
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_reading_marketing_does_not_let_you_answer(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->facebook($t);
        $this->fakeNetworks();

        $this->signedInAs($this->userWithRole($t, 'Viewer', ['marketing.view', 'marketing.comments.view']));

        $this->getJson('/api/v1/social/comments')->assertOk();
        $this->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/replies", ['message' => 'Hi'])->assertForbidden();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_sibling_franchises_accounts_are_neither_listed_nor_reachable(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Sibling');
        $page = $this->connection($t, 'facebook_page', 'page-1', ['access_token' => 'page-token', 'scopes' => ['pages_manage_engagement']], 'Sibling Page', 'franchise', $sibling->id);
        $this->fakeNetworks();

        $this->signedInAs($this->userWithRole($t, 'Branch', ['marketing.view', 'marketing.comments.view', 'marketing.edit'], onlyAt: $t['franchise']));

        $this->getJson('/api/v1/social/comments')->assertOk()->assertJsonPath('data', [])->assertJsonPath('sources', []);
        $this->postJson("/api/v1/social/connections/{$page->id}/comments/facebook/fb-c1/replies", ['message' => 'Hi'])->assertNotFound();
        Http::assertNothingSent();
    }
}
