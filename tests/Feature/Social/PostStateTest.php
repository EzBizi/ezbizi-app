<?php

namespace Tests\Feature\Social;

use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Social\Publishing\GoogleBusinessProfilePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Suggestion 01a1ef57 / 01a1ef71: six work states read from the target, the
 * schedule and the provider's receipt. A creation time is never a schedule,
 * and approving is not releasing. Disposable QA rows only; no provider call.
 */
class PostStateTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, fn () => $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $type.':'.$id]),
        ])->save());
    }

    /** @return array{0: array, 1: SocialConnection, 2: BusinessLocation} */
    private function googleListing(): array
    {
        $t = $this->provisionTenant('QA Social');
        [$c, $place] = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'google_business_profile', 'remote_id' => 'accounts/qa', 'display_name' => 'QA GBP'])->save();
            $place = new BusinessLocation;
            $place->forceFill(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'connection_id' => $c->id,
                'remote_name' => 'locations/qa1', 'title' => 'QA Chimney · Washington, DC', 'city' => 'Washington',
                'verification_state' => 'VERIFIED'])->save();

            return [$c, $place];
        });

        return [$t, $c, $place];
    }

    private function qaPost(array $t, SocialConnection $c, string $status, ?string $at, array $extra = []): SocialPost
    {
        return $this->inTenant($t['org'], function () use ($t, $c, $status, $at, $extra) {
            $post = SocialPost::create(['body' => 'QA disposable post body for state tests.', 'created_by' => $t['admin']->id,
                'scheduled_for' => $at, 'scheduled_timezone' => $at ? 'America/New_York' : null]);
            $target = $post->targets()->create(['social_connection_id' => $c->getKey(), 'status' => $status,
                'options' => ['gbp_title' => 'QA Chimney · Washington, DC', 'cta_type' => 'LEARN_MORE', 'cta_url' => 'https://example.test/book']]);
            if ($extra !== []) {
                $target->forceFill($extra)->save();
            }

            return $post;
        });
    }

    public function test_each_post_carries_one_state_and_the_tiles_count_the_same_reading(): void
    {
        [$t, $c] = $this->googleListing();
        $past = now('America/New_York')->subDay()->format('Y-m-d H:i:s');
        $ahead = now('America/New_York')->addDays(3)->format('Y-m-d H:i:s');

        $washington = $this->qaPost($t, $c, 'awaiting_approval', null);
        $heldWithTime = $this->qaPost($t, $c, 'awaiting_approval', $ahead);
        $elapsedProposal = $this->qaPost($t, $c, 'awaiting_approval', $past);
        $ready = $this->qaPost($t, $c, 'scheduled', null);
        $scheduled = $this->qaPost($t, $c, 'scheduled', $ahead);
        $missed = $this->qaPost($t, $c, 'scheduled', $past);
        $published = $this->qaPost($t, $c, 'sent', null, ['url' => 'https://example.test/p/1', 'sent_at' => now()]);
        $failed = $this->qaPost($t, $c, 'failed', null, ['failure' => 'QA refusal']);

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $response = $this->signedInAs($t['admin']->fresh())->getJson(self::ENDPOINT)->assertOk()
            ->assertJsonPath('meta.tiles', ['awaiting' => 2, 'ready' => 1, 'scheduled' => 1, 'past_due' => 2, 'sent' => 1, 'failed' => 1]);

        $states = collect($response->json('data'))->pluck('state', 'id');
        $this->assertSame('awaiting', $states[$washington->id], 'awaiting with no time is Needs approval, never Scheduled');
        $this->assertSame('awaiting', $states[$heldWithTime->id]);
        $this->assertSame('past_due', $states[$elapsedProposal->id]);
        $this->assertSame('ready', $states[$ready->id]);
        $this->assertSame('scheduled', $states[$scheduled->id]);
        $this->assertSame('past_due', $states[$missed->id]);
        $this->assertSame('sent', $states[$published->id]);
        $this->assertSame('failed', $states[$failed->id]);

        // The Washington case keeps its creation time apart from a schedule.
        $row = collect($response->json('data'))->firstWhere('id', $washington->id);
        $this->assertNull($row['scheduled_for']);
        $this->assertNotNull($row['created_at']);
        $this->assertSame('LEARN_MORE', $row['targets'][0]['cta_type']);
        $this->assertSame($t['company']->name, $row['targets'][0]['company']);
        $this->assertSame('connected', $row['targets'][0]['connection_status']);

        // The filter returns exactly the tile's posts.
        $this->signedInAs($t['admin']->fresh())->getJson(self::ENDPOINT.'?status=past_due')
            ->assertJsonCount(2, 'data');
        $this->signedInAs($t['admin']->fresh())->getJson(self::ENDPOINT.'?status=scheduled')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $scheduled->id);
    }

    public function test_a_mixed_post_reads_failed_and_keeps_each_targets_receipt(): void
    {
        [$t, $c] = $this->googleListing();
        $post = $this->qaPost($t, $c, 'sent', null, ['url' => 'https://example.test/p/2', 'sent_at' => now()]);
        $this->inTenant($t['org'], fn () => $post->targets()->create(['social_connection_id' => $c->getKey(),
            'status' => 'failed'])->forceFill(['failure' => 'QA refusal'])->save());

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $row = $this->signedInAs($t['admin']->fresh())->getJson(self::ENDPOINT)->assertOk()->json('data.0');
        $this->assertSame('failed', $row['state']);
        $this->assertEqualsCanonicalizing(['sent', 'failed'], array_column($row['targets'], 'status'));
        $this->assertContains('https://example.test/p/2', array_column($row['targets'], 'url'));
    }

    public function test_approving_a_post_with_a_time_ahead_schedules_it_and_sends_nothing_now(): void
    {
        Bus::fake([PublishSocialTarget::class]);
        Process::fake();
        [$t, $c, $place] = $this->googleListing();
        $ahead = now('America/New_York')->addDays(2)->format('Y-m-d H:i:s');
        $post = $this->qaPost($t, $c, 'awaiting_approval', $ahead);
        $target = $post->targets()->withoutGlobalScopes()->first();
        $this->inTenant($t['org'], fn () => $target->forceFill(['options' => $target->options
            + ['gbp_location' => GoogleBusinessProfilePublisher::v4Name($place, $c)]])->save());

        $this->signedInAs($t['admin'])->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'scheduled');

        Bus::assertNotDispatched(PublishSocialTarget::class);
        $this->assertSame('scheduled', SocialPostTarget::withoutGlobalScopes()->find($target->id)->status);
        // Twice is one decision.
        $this->signedInAs($t['admin'])->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve")->assertStatus(409);
    }

    public function test_approving_after_the_planned_time_returns_to_scheduling_instead_of_sending(): void
    {
        Bus::fake([PublishSocialTarget::class]);
        Process::fake();
        [$t, $c, $place] = $this->googleListing();
        $post = $this->qaPost($t, $c, 'awaiting_approval', now('America/New_York')->subHour()->format('Y-m-d H:i:s'));
        $target = $post->targets()->withoutGlobalScopes()->first();
        $this->inTenant($t['org'], fn () => $target->forceFill(['options' => $target->options
            + ['gbp_location' => GoogleBusinessProfilePublisher::v4Name($place, $c)]])->save());

        $this->signedInAs($t['admin'])->postJson("/api/v1/business-profile/post-queue/{$target->id}/approve")
            ->assertStatus(422)->assertJsonPath('message', 'Its planned time has passed. Pick a new time, then approve it.');

        Bus::assertNotDispatched(PublishSocialTarget::class);
        $this->assertSame('awaiting_approval', SocialPostTarget::withoutGlobalScopes()->find($target->id)->status);
    }
}
