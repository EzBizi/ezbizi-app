<?php

namespace Tests\Feature\Automations;

use App\Models\A1LeadsReviewEvent;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\User;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * "Reply on Google" — Jose, 2026-09-25: "this will be set using EzBizi
 * Automations that Bar himself will set up for the reply."
 *
 * The HTTP POST to A1 Leads' live writer is the artefact asserted, not a
 * return value: a step that said "Replied" without posting would pass any
 * test that only read the detail.
 */
class ReplyOnGoogleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LIVE = 'http://127.0.0.1:3457';

    private const REVIEW = 'accounts/1/locations/2/reviews/r-rule';

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $this->tenant['org']->id);
        config()->set('services.a1leads.company_id', $this->tenant['company']->id);
        config()->set('services.a1leads.live_reply_url', self::LIVE);
        config()->set('services.a1leads.live_reply_key', 'dedicated-reply-key');
    }

    private function fakeWriter(bool $canReply = true): void
    {
        Http::fake([
            self::LIVE.'*' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response(['data' => [
                    'googleReviewName' => self::REVIEW, 'reviewUpdatedAt' => '2026-09-25T00:33:23Z',
                    'replyStatus' => $canReply ? 'none' : 'replied', 'canReply' => $canReply,
                ]])
                : Http::response(['data' => ['ok' => true, 'googleReviewName' => self::REVIEW]]),
        ]);
    }

    private function reply(User $author, string $message = 'Thank you, {{reviewer_first_name}}.'): array
    {
        return $this->inTenant($this->tenant['org'], function () use ($author, $message) {
            $org = $this->tenant['org']->id;
            $automation = Automation::create(['organization_id' => $org, 'name' => 'Five stars',
                'trigger_key' => 'a1leads_gbp.review_received', 'is_active' => true]);
            $version = AutomationVersion::create(['organization_id' => $org, 'automation_id' => $automation->id,
                'version' => 1, 'conditions' => null, 'actions' => [], 'created_by' => $author->id,
                'created_at' => now()]);
            $automation->forceFill(['active_version_id' => $version->id])->save();

            $review = A1LeadsReviewEvent::create(['organization_id' => $org,
                'company_id' => $this->tenant['company']->id, 'google_review_name' => self::REVIEW,
                'rating' => 5, 'reviewer_name' => 'Dana Reyes', 'has_reply' => false]);
            $event = AutomationEvent::create(['organization_id' => $org,
                'event_key' => 'a1leads_gbp.review_received',
                'payload' => ['after' => ['reviewer_first_name' => 'Dana']],
                'occurred_at' => now(), 'available_at' => now()]);

            return app(ActionRunner::class)->run(['type' => 'review_reply', 'message' => $message],
                $event, $review, automation: $automation->fresh());
        });
    }

    public function test_a_rule_posts_the_reply_through_a1_leads(): void
    {
        $this->fakeWriter();

        $result = $this->reply($this->tenant['admin']);

        $this->assertTrue($result['ok'], $result['detail']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r['text'] === 'Thank you, Dana.');
    }

    public function test_an_answered_review_is_left_alone_and_is_not_a_failure(): void
    {
        $this->fakeWriter(canReply: false);

        $result = $this->reply($this->tenant['admin']);

        $this->assertTrue($result['ok'], 'already answered must not count toward switching the rule off');
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    /**
     * A rule never widens what its author may do. The admin case above is the
     * control that the same step, by somebody who may reply, does post.
     */
    public function test_a_rule_by_somebody_who_may_not_reply_posts_nothing(): void
    {
        $this->fakeWriter();
        $clerk = $this->userWithRole($this->tenant, 'Clerk', ['a1leads_gbp.view', 'automations.view', 'automations.edit']);

        $result = $this->reply($clerk);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('may not reply', $result['detail']);
        Http::assertNothingSent();
    }
}
