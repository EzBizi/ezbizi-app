<?php

namespace Tests\Feature\Reviews;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationVersion;
use App\Models\BusinessLocation;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Review auto-reply (A1 Leads takeover, 2026-10-20): a rule publishes only on
 * listings a person switched on, only as an author who may publish by hand,
 * and never over another reply.
 */
class ReviewAutoReplyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private BusinessLocation $location;

    private Review $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        config(['services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            '*/reply' => Http::response(['comment' => 'ok']),
        ]);
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Hi Dana, thank you — we loved helping.']]))]);

        [$this->location, $this->review] = $this->inTenant($this->t['org'], function () {
            $c = SocialConnection::create(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->t['org']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/9', 'display_name' => 'Co']);
            $c->connect(['refresh_token' => 'rt']);
            $c->save();
            $location = BusinessLocation::create(['organization_id' => $this->t['org']->id, 'connection_id' => $c->id,
                'remote_name' => 'locations/55', 'account_name' => 'accounts/9', 'title' => 'North']);
            $review = Review::create(['organization_id' => $this->t['org']->id, 'connection_id' => $c->id,
                'remote_name' => 'accounts/9/locations/55/reviews/r1', 'location_name' => 'accounts/9/locations/55',
                'reviewer_name' => 'Dana', 'rating' => 5, 'reviewed_at' => now()]);

            return [$location, $review];
        });
    }

    private function fire(?User $author = null, array $action = ['type' => 'publish_reply', 'ai' => true]): array
    {
        return $this->inTenant($this->t['org'], function () use ($author, $action) {
            $automation = Automation::create(['organization_id' => $this->t['org']->id, 'name' => 'Good reviews',
                'trigger_key' => 'review.received', 'is_active' => true]);
            $version = AutomationVersion::create(['organization_id' => $this->t['org']->id, 'automation_id' => $automation->id,
                'version' => 1, 'conditions' => null, 'actions' => [$action], 'created_at' => now(),
                'created_by' => ($author ?? $this->t['admin'])->id]);
            $automation->forceFill(['active_version_id' => $version->id])->save();
            $event = AutomationEvent::create(['organization_id' => $this->t['org']->id, 'event_key' => 'review.received',
                'subject_type' => Review::class, 'subject_id' => $this->review->id,
                'payload' => ['after' => ['reviewer_name' => 'Dana', 'rating' => 5]], 'occurred_at' => now(), 'available_at' => now()]);

            return app(ActionRunner::class)->run($action, $event, $this->review, automation: $automation->fresh());
        });
    }

    private function published(): int
    {
        return ReviewReply::withoutGlobalScopes()->where('status', ReviewReply::STATUS_PUBLISHED)->count();
    }

    public function test_off_by_default_it_posts_nothing_and_is_not_a_failure(): void
    {
        $result = $this->fire();

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped'] ?? false);
        $this->assertSame(0, $this->published());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_switched_on_it_publishes_once_and_never_over_another_reply(): void
    {
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])
            ->assertOk()->assertJsonPath('data.auto_reply', true);

        $result = $this->fire();
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $this->published());
        // Written by AI for this review; dashes are never left in.
        $this->assertSame('Hi Dana, thank you, we loved helping.', ReviewReply::withoutGlobalScopes()->first()->body);
        $this->assertSame(1, AutomationEvent::withoutGlobalScopes()->where('event_key', 'review.reply_published')->count());

        $again = $this->fire();
        $this->assertTrue($again['skipped'] ?? false, 'already answered');
        Http::assertSentCount(2); // one token, one reply
    }

    public function test_an_author_who_may_not_publish_posts_nothing(): void
    {
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])->assertOk();
        $drafter = $this->userWithRole($this->t, 'Drafter', ['reviews.view', 'reviews.edit']);

        $result = $this->fire($drafter);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->published());
    }

    public function test_the_switch_needs_the_publish_grant(): void
    {
        $editor = $this->userWithRole($this->t, 'Editor', ['business_profile.view', 'business_profile.edit']);
        $this->signedInAs($editor)->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])
            ->assertForbidden();
        $this->assertFalse($this->location->fresh()->auto_reply);
    }

    /** Jose, 2026-10-20: "they can't be all the same". The prompt is this review's, in one of several styles. */
    public function test_the_ai_is_asked_about_this_review_and_a_stars_only_one_is_not_quoted(): void
    {
        $prompts = collect(range(1, 30))->map(fn () => $this->inTenant($this->t['org'],
            fn () => \App\Support\Reviews\ReviewReplyWriter::prompt($this->review->fresh())));

        $this->assertStringContainsString('"Dana"', $prompts[0]);
        $this->assertStringContainsString('stars only and NO text', $prompts[0]);
        $this->assertGreaterThan(1, $prompts->unique()->count(), 'thirty asks, one style every time');
    }

    public function test_no_ai_answer_or_an_old_review_posts_nothing(): void
    {
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])->assertOk();

        Process::fake(['*' => Process::result('', 'signed out', 1)]);
        $this->assertTrue($this->fire()['skipped'] ?? false);

        $this->inTenant($this->t['org'], fn () => $this->review->forceFill(['reviewed_at' => now()->subDays(45)])->save());
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['reply' => 'Thanks']]))]);
        $this->assertStringContainsString('days old', $this->fire()['detail']);

        $this->assertSame(0, $this->published());
    }

    /** Jose, 2026-11-02: "if i turn it on, it should just start working". The switch makes the one rule, once. */
    public function test_the_switch_makes_the_rule_once_and_its_words_follow_the_stars(): void
    {
        $on = fn () => $this->signedInAs($this->t['admin'])
            ->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])->assertOk();
        $on();
        $on();
        $rules = $this->inTenant($this->t['org'], fn () => Automation::query()->where('name', \App\Support\Reviews\ReviewAutoReplyRule::NAME)->get());
        $this->assertCount(1, $rules);
        $this->assertTrue($rules[0]->is_active);
        $this->assertSame('review.received', $rules[0]->trigger_key);

        $this->inTenant($this->t['org'], fn () => $this->review->forceFill(['rating' => 2])->save());
        $action = $this->inTenant($this->t['org'], fn () => $rules[0]->activeVersion->actions[0]);
        $this->assertTrue($this->fire(action: $action)['ok']);
        $this->assertSame(\App\Support\Reviews\ReviewAutoReplyRule::REPLIES['2'], ReviewReply::withoutGlobalScopes()->first()->body);
    }

    public function test_the_backfill_makes_the_rule_for_listings_switched_on_before(): void
    {
        $this->signedInAs($this->t['admin'])->putJson("/api/v1/business-profile/locations/{$this->location->id}/auto-reply", ['on' => true])->assertOk();
        $this->inTenant($this->t['org'], fn () => Automation::query()->where('name', \App\Support\Reviews\ReviewAutoReplyRule::NAME)->get()
            ->each(fn ($a) => $a->versions()->delete() + $a->forceDelete()));

        $this->artisan('ezbizi:review-auto-reply-rule')->assertSuccessful();

        $this->assertSame(1, $this->inTenant($this->t['org'], fn () => Automation::query()->where('name', \App\Support\Reviews\ReviewAutoReplyRule::NAME)->count()));
    }
}
