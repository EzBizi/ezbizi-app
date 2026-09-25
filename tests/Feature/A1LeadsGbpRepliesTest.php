<?php

namespace Tests\Feature;

use App\Models\A1LeadsReplyReceipt;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Services\A1Leads\A1LeadsGbpReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The automation's way through the one path to Google.
 *
 * An automation saw no review, so the service reads it live first - and the
 * case that must never post is a review that already has a reply.
 */
class A1LeadsGbpRepliesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LIVE = 'http://127.0.0.1:3457';

    private const REVIEW = 'accounts/1/locations/2/reviews/r-auto';

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.live_reply_url', self::LIVE);
        config()->set('services.a1leads.live_reply_key', 'dedicated-reply-key');
    }

    private function automation(): Automation
    {
        return $this->inTenant($this->tenant['org'], fn () => Automation::create([
            'organization_id' => $this->tenant['org']->id,
            'name' => 'Reply to new 5-star Google reviews',
            'trigger_key' => 'a1leads_gbp.review_received',
            'is_active' => true,
        ]));
    }

    private function publish(Automation $automation): array
    {
        return $this->inTenant($this->tenant['org'], fn () => app(A1LeadsGbpReplies::class)
            ->publish(self::REVIEW, 'Thank you, Dana!', null, null, $automation));
    }

    public function test_an_automation_posts_against_the_live_revision_and_is_audited_as_itself(): void
    {
        Http::fake([
            self::LIVE.'*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['data' => [
                    'googleReviewName' => self::REVIEW, 'reviewUpdatedAt' => '2026-09-25T00:33:23Z',
                    'replyStatus' => 'none', 'canReply' => true,
                ]])
                : Http::response(['data' => [
                    'ok' => true, 'googleReviewName' => self::REVIEW, 'text' => 'Thank you, Dana!',
                    'sentAt' => '2026-09-25T10:00:00Z',
                ]]),
        ]);
        $automation = $this->automation();

        $result = $this->publish($automation);

        $this->assertTrue($result['body']['data']['ok']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['expectedReviewUpdatedAt'] === '2026-09-25T00:33:23Z'
            && $r['actorId'] === 'automation:'.$automation->id);

        $this->inTenant($this->tenant['org'], function () use ($automation) {
            $event = AuditEvent::query()->where('action', 'a1leads_gbp.review_replied')->sole();
            $this->assertSame($automation->id, $event->metadata['automation_id']);
            $this->assertNull(A1LeadsReplyReceipt::query()->sole()->actor_id, 'no person spoke');
        });
    }

    public function test_an_automation_never_answers_a_review_that_has_a_reply(): void
    {
        Http::fake([
            self::LIVE.'*' => Http::response(['data' => [
                'googleReviewName' => self::REVIEW, 'reviewUpdatedAt' => '2026-09-25T00:33:23Z',
                'replyStatus' => 'sent', 'canReply' => false,
            ]]),
        ]);
        $automation = $this->automation();

        $result = $this->publish($automation);

        $this->assertSame(409, $result['status']);
        $this->assertSame('already_answered', $result['body']['error']['code']);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $this->inTenant($this->tenant['org'], function () use ($automation) {
            $event = AuditEvent::query()->where('action', 'a1leads_gbp.review_reply_failed')->sole();
            $this->assertSame('already_answered', $event->metadata['code']);
            $this->assertSame($automation->id, $event->metadata['automation_id']);
        });
    }
}
