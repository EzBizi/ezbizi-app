<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Comments on the Instagram account linked to a connected Facebook Page.
 */
class InstagramCommentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function page(array $t, string $scopeType = 'organization', ?string $scopeId = null, string $provider = 'facebook_page'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId, $provider) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId ?? $t['org']->id,
                'provider' => $provider,
                'remote_id' => 'page-1',
                'display_name' => 'EzBizi',
            ]);
            $row->connect(['access_token' => 'page-token']);
            $row->save();

            return $row;
        });
    }

    private function instagram(): void
    {
        Http::fake([
            'graph.facebook.com/v21.0/page-1?*' => Http::response(['instagram_business_account' => ['id' => 'ig-9', 'username' => 'ezbizi']]),
            'graph.facebook.com/v21.0/ig-9/media*' => Http::response(['data' => [
                ['id' => 'm-1', 'caption' => 'Chimney season', 'permalink' => 'https://www.instagram.com/p/x/', 'comments_count' => 1],
                ['id' => 'm-2', 'caption' => 'Quiet one', 'comments_count' => 0],
            ]]),
            'graph.facebook.com/v21.0/m-1/comments*' => Http::response(['data' => [
                ['id' => 'c-1', 'text' => 'Do you serve Tampa?', 'username' => 'homeowner', 'hidden' => false,
                    'replies' => ['data' => [['id' => 'r-1', 'text' => 'Yes!', 'username' => 'ezbizi']]]],
            ]]),
            'graph.facebook.com/v21.0/c-1/replies' => Http::response(['id' => 'r-2']),
            'graph.facebook.com/v21.0/c-1' => Http::response(['success' => true]),
        ]);
    }

    public function test_the_comments_on_recent_posts_are_listed_with_their_replies(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);
        $this->instagram();

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/social/connections/{$page->id}/instagram-comments")
            ->assertOk()
            ->assertJsonPath('data.account.username', 'ezbizi')
            ->assertJsonPath('data.posts.0.comments.0.text', 'Do you serve Tampa?')
            ->assertJsonPath('data.posts.0.comments.0.replies.0.text', 'Yes!')
            ->assertJsonPath('data.posts.1.comments', []);

        // Every call carried the PAGE's token - comments are moderated through it.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'access_token=page-token'));
    }

    public function test_a_page_with_no_linked_instagram_says_so_rather_than_failing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'page-1'])]);

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/social/connections/{$page->id}/instagram-comments")
            ->assertOk()
            ->assertJsonPath('data.account', null)
            ->assertJsonPath('data.posts', []);
    }

    public function test_a_reply_and_a_hide_reach_instagram_and_are_audited(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);
        $this->instagram();

        $this->signedInAs($t['admin'])
            ->postJson("/api/v1/social/connections/{$page->id}/instagram-comments/c-1/replies", ['message' => 'Yes, all of Tampa Bay.'])
            ->assertOk()->assertJsonPath('data.id', 'r-2');
        $this->postJson("/api/v1/social/connections/{$page->id}/instagram-comments/c-1/hide", ['hide' => true])
            ->assertOk()->assertJsonPath('data.hidden', true);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/c-1/replies') && $r['message'] === 'Yes, all of Tampa Bay.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/c-1') && $r['hide'] === 'true');
        $this->assertDatabaseHas('audit_events', ['action' => 'social.instagram_comment_replied']);
        $this->assertDatabaseHas('audit_events', ['action' => 'social.instagram_comment_hidden']);
    }

    public function test_reading_marketing_does_not_let_you_answer_for_the_business(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->page($t);
        $this->instagram();

        $this->signedInAs($this->userWithRole($t, 'Viewer', ['marketing.view']));

        $this->getJson("/api/v1/social/connections/{$page->id}/instagram-comments")->assertOk();
        $this->postJson("/api/v1/social/connections/{$page->id}/instagram-comments/c-1/replies", ['message' => 'Hi'])->assertForbidden();
        $this->postJson("/api/v1/social/connections/{$page->id}/instagram-comments/c-1/hide", ['hide' => true])->assertForbidden();
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_sibling_franchises_page_is_not_found(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Sibling');
        $page = $this->page($t, 'franchise', $sibling->id);
        $this->instagram();

        $this->signedInAs($this->userWithRole($t, 'Branch', ['marketing.view', 'marketing.edit'], onlyAt: $t['franchise']));

        $this->getJson("/api/v1/social/connections/{$page->id}/instagram-comments")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_only_a_facebook_page_carries_instagram_comments(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->page($t, provider: SocialProvider::Threads->value);
        Http::fake();

        $this->signedInAs($t['admin'])
            ->getJson("/api/v1/social/connections/{$row->id}/instagram-comments")
            ->assertUnprocessable();
    }
}
