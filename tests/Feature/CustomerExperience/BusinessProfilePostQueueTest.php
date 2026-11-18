<?php

namespace Tests\Feature\CustomerExperience;

use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\SocialPostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 2026-11-03: weekly AI Google posts wait for Approve / Disapprove; auto-post stays off. */
class BusinessProfilePostQueueTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_weekly_posts_wait_for_approval_once_a_week_and_approve_or_disapprove_decides(): void
    {
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        Bus::fake([PublishSocialTarget::class]);
        $t = $this->provisionTenant('Alpha');
        [$one, $two] = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'google_business_profile', 'remote_id' => 'accounts/1', 'display_name' => 'GBP'])->save();
            $make = fn (string $l) => tap(new BusinessLocation, fn ($p) => $p->forceFill(['organization_id' => $t['org']->id,
                'company_id' => $t['company']->id, 'connection_id' => $c->id, 'remote_name' => "locations/{$l}", 'title' => "L{$l}", 'city' => 'Dayton', 'verification_state' => 'VERIFIED'])->save());

            return [$make('1'), $make('2')];
        });
        Process::fake(['*' => Process::sequence()
            ->push(Process::result(json_encode(['structuredOutput' => ['post' => 'Fall is here, so book your chimney sweep before the first fire of the season.']])))
            ->push(Process::result(json_encode(['structuredOutput' => ['post' => 'A cracked crown lets water in all winter; have it checked while the weather is dry.']])))
            ->push(Process::result(json_encode(['structuredOutput' => ['post' => 'Birds love a warm chimney in spring; a cap keeps them and the rain out.']])))
            ->push(Process::result(json_encode(['structuredOutput' => ['post' => 'Smell smoke indoors? A blocked flue is often the reason, and a sweep clears it.']])))]);

        $this->artisan('ezbizi:gbp-weekly-posts')->assertSuccessful();
        $this->artisan('ezbizi:gbp-weekly-posts')->assertSuccessful();

        $this->signedInAs($t['admin']);
        $queue = $this->getJson('/api/v1/business-profile/post-queue')->assertOk()->json('data');
        $this->assertCount(2, $queue, 'one waiting per listing, not one per run');
        $this->assertNotSame($queue[0]['body'], $queue[1]['body']);
        Bus::assertNotDispatched(PublishSocialTarget::class);

        $this->postJson("/api/v1/business-profile/post-queue/{$queue[0]['id']}/approve", ['publish_now' => true])->assertOk();
        Bus::assertDispatched(PublishSocialTarget::class, 1);
        $this->postJson("/api/v1/business-profile/post-queue/{$queue[1]['id']}/disapprove", ['reason' => 'Off topic'])->assertOk();
        $this->postJson("/api/v1/business-profile/post-queue/{$queue[1]['id']}/approve")->assertStatus(409);

        $this->assertSame(SocialPostTarget::STATUS_DISAPPROVED, SocialPostTarget::withoutGlobalScopes()->find($queue[1]['id'])->status);
        // Jose 2026-11-04: each decision writes that listing's next one, so the tab is never empty.
        $next = $this->getJson('/api/v1/business-profile/post-queue')->json('data');
        $this->assertCount(2, $next);
        $this->assertEmpty(array_intersect(array_column($next, 'id'), [$queue[0]['id'], $queue[1]['id']]));
        $this->assertFalse($one->fresh()->auto_post);
    }
}
