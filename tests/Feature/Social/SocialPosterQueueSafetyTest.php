<?php

namespace Tests\Feature\Social;

use App\Console\Commands\WeeklyBusinessProfilePosts;
use App\Http\Controllers\Api\BusinessProfilePostQueueController;
use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Bug review 2026-11-09, findings 1, 3, 4 and 10: a send that dies must not
 * leave its target `queued` for good, one draft is sent once however many
 * times it is approved, and the approval queue is the view's, not the org's.
 */
class SocialPosterQueueSafetyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_laravels_failure_hook_marks_a_queued_target_failed_with_a_sentence(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $target = $this->target($t, $this->connection($t, $t['company']->id), SocialPostTarget::STATUS_QUEUED);

        // What the worker calls on a timeout: one argument, the Throwable.
        (new PublishSocialTarget($target->id))->failed(new RuntimeException('Job has timed out.'));

        $saved = SocialPostTarget::withoutGlobalScopes()->find($target->id);
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $saved->status);
        $this->assertStringContainsString('Check Facebook before you try again', (string) $saved->failure);
    }

    public function test_the_failure_hook_leaves_a_target_that_already_landed_alone(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $target = $this->target($t, $this->connection($t, $t['company']->id), SocialPostTarget::STATUS_SENT);

        (new PublishSocialTarget($target->id))->failed(new RuntimeException('late'));

        $this->assertSame(SocialPostTarget::STATUS_SENT, SocialPostTarget::withoutGlobalScopes()->find($target->id)->status);
    }

    public function test_a_send_outlasts_the_longest_upload_and_runs_on_the_long_worker(): void
    {
        config(['queue.default' => 'database']);
        $job = new PublishSocialTarget('x');

        $this->assertGreaterThan(120, $job->timeout);
        $this->assertSame('database-long', $job->connection);
        $this->assertSame('long', $job->queue);
        $this->assertGreaterThan($job->timeout, config('queue.connections.database-long.retry_after'));
    }

    public function test_the_sweep_fails_a_target_stuck_queued_and_leaves_a_fresh_one(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, $t['company']->id);
        $stuck = $this->target($t, $page, SocialPostTarget::STATUS_QUEUED);
        $fresh = $this->target($t, $page, SocialPostTarget::STATUS_QUEUED);
        SocialPostTarget::withoutGlobalScopes()->whereKey($stuck->id)->toBase()->update(['updated_at' => now()->subMinutes(31)]);

        $this->artisan('ezbizi:publish-due-social-posts')->assertSuccessful();

        $stuck = SocialPostTarget::withoutGlobalScopes()->find($stuck->id);
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $stuck->status);
        $this->assertStringContainsString('cannot tell whether the post went out', (string) $stuck->failure);
        $this->assertSame(SocialPostTarget::STATUS_QUEUED, SocialPostTarget::withoutGlobalScopes()->find($fresh->id)->status);
    }

    public function test_the_job_sends_nothing_for_a_target_that_is_no_longer_queued(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $target = $this->target($t, $this->connection($t, $t['company']->id), SocialPostTarget::STATUS_SENT);
        Http::fake();

        PublishSocialTarget::dispatchSync($target->id);

        Http::assertNothingSent();
    }

    public function test_a_second_approve_of_the_same_draft_sends_nothing(): void
    {
        Bus::fake([PublishSocialTarget::class]);
        $t = $this->provisionTenant('A1-Group');
        $gbp = $this->connection($t, $t['company']->id, 'google_business_profile');
        $place = $this->place($t, $gbp, 'Miami');
        $target = $this->draft($t, $gbp, $place);

        // Two tabs both loaded the draft while it was waiting; the first wins.
        $stale = SocialPostTarget::withoutGlobalScopes()->find($target->id);
        $this->signedInAs($t['admin'])->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve", ['publish_now' => true])->assertOk();

        try {
            $this->inTenant($t['org'], fn () => app(BusinessProfilePostQueueController::class)
                ->approve(Request::create('/', 'POST'), $stale));
            $this->fail('A second approve went through.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        Bus::assertDispatchedTimes(PublishSocialTarget::class, 1);
    }

    public function test_the_approval_queue_lists_only_the_drafts_of_the_company_in_view(): void
    {
        Bus::fake([PublishSocialTarget::class]);
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create(['organization_id' => $t['org']->id, 'name' => 'Premier Garage']));
        $mine = $this->connection($t, $t['company']->id, 'google_business_profile', 'accounts/1');
        $theirs = $this->connection($t, $premier->id, 'google_business_profile', 'accounts/2');
        $this->draft($t, $mine, $this->place($t, $mine, 'Mine'));
        $this->draft($t, $theirs, $this->place($t, $theirs, 'Theirs'));

        $this->lookingAt($t['admin'], 'company', $t['company']->id);

        $names = $this->signedInAs($t['admin']->fresh())->getJson('/api/v1/business-profile/post-queue')
            ->assertOk()->json('data.*.listing.name');

        $this->assertCount(1, $names);
        $this->assertStringStartsWith('Mine', $names[0]);
    }

    public function test_no_weekly_draft_is_written_for_a_listing_google_is_not_showing(): void
    {
        // AI switched on and answering, so only the listing's state can stop the draft.
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'post' => 'Fall is here, so book your chimney sweep before the first fire of the season.',
        ]]))]);
        $t = $this->provisionTenant('A1-Group');
        $gbp = $this->connection($t, $t['company']->id, 'google_business_profile');
        $down = $this->place($t, $gbp, 'Old Shop', 'SUSPENDED');

        $this->assertFalse($this->inTenant($t['org'], fn () => WeeklyBusinessProfilePosts::draftFor($down->load('connection'))));
        Process::assertNothingRan();

        // The control: the same setup does draft for a live listing.
        $live = $this->place($t, $gbp, 'New Shop');
        $this->assertTrue($this->inTenant($t['org'], fn () => WeeklyBusinessProfilePosts::draftFor($live->load('connection'))));
    }

    public function test_a_draft_for_a_listing_that_went_down_is_not_approved(): void
    {
        Bus::fake([PublishSocialTarget::class]);
        $t = $this->provisionTenant('A1-Group');
        $gbp = $this->connection($t, $t['company']->id, 'google_business_profile');
        $place = $this->place($t, $gbp, 'Old Shop');
        $target = $this->draft($t, $gbp, $place);
        $this->inTenant($t['org'], fn () => $place->forceFill(['verification_state' => 'SUSPENDED'])->save());

        $this->signedInAs($t['admin'])->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve")->assertStatus(422);

        Bus::assertNotDispatched(PublishSocialTarget::class);
        $this->assertSame(SocialPostTarget::STATUS_AWAITING_APPROVAL, SocialPostTarget::withoutGlobalScopes()->find($target->id)->status);
    }

    private function connection(array $t, string $companyId, string $provider = 'facebook_page', string $remoteId = 'page-1'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $companyId, $provider, $remoteId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $companyId,
                'provider' => $provider, 'remote_id' => $remoteId, 'display_name' => 'Account '.$remoteId,
            ]);
            $row->connect(['refresh_token' => 'r', 'access_token' => 'a']);
            $row->save();

            return $row;
        });
    }

    private function target(array $t, SocialConnection $connection, string $status): SocialPostTarget
    {
        return $this->inTenant($t['org'], function () use ($connection, $status) {
            $post = SocialPost::create(['body' => 'Chimney season']);

            return $post->targets()->create(['social_connection_id' => $connection->id, 'status' => $status]);
        });
    }

    private function place(array $t, SocialConnection $gbp, string $title, string $state = 'VERIFIED'): BusinessLocation
    {
        return $this->inTenant($t['org'], fn () => tap(new BusinessLocation, fn ($p) => $p->forceFill([
            'organization_id' => $t['org']->id, 'company_id' => $gbp->scope_id, 'connection_id' => $gbp->id,
            'remote_name' => 'locations/'.strtolower(str_replace(' ', '', $title)), 'title' => $title,
            'verification_state' => $state, 'is_published' => true, 'city' => 'Dayton',
        ])->save()));
    }

    private function draft(array $t, SocialConnection $gbp, BusinessLocation $place): SocialPostTarget
    {
        return $this->inTenant($t['org'], function () use ($gbp, $place) {
            $post = SocialPost::create(['body' => 'Fall is here, so book your chimney sweep before the first fire.']);

            return $post->targets()->create([
                'social_connection_id' => $gbp->id,
                'options' => ['gbp_location' => \App\Support\Social\Publishing\GoogleBusinessProfilePublisher::v4Name($place, $gbp), 'gbp_title' => $place->title],
                'status' => SocialPostTarget::STATUS_AWAITING_APPROVAL,
            ]);
        });
    }

    private function lookingAt(User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, fn () => $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id]),
        ])->save());
    }
}
