<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\ThreadsPublisher;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Bug review 2026-11-09, findings 7, 8, 9, 11 and 12 on Compose and Posts. */
class SocialPostsReviewFixesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    /** #7: a turned-down draft, or a post taken back down, never went out. */
    public function test_disapproved_and_removed_posts_are_not_in_the_sent_tile(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'facebook_page', 'page-1');

        $this->inTenant($t['org'], function () use ($t, $page) {
            foreach (['sent', 'disapproved', 'removed'] as $status) {
                SocialPost::create(['body' => $status, 'created_by' => $t['admin']->id])
                    ->targets()->create(['social_connection_id' => $page->id, 'status' => $status]);
            }
        });

        $this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->assertJsonPath('meta.tiles.sent', 1);
        $this->assertSame(['sent'], $this->signedInAs($t['admin'])->getJson(self::ENDPOINT.'?status=sent')->json('data.*.body'));
    }

    /** #8: too long for Threads is refused at compose for Threads only; the page still goes. */
    public function test_a_post_too_long_for_threads_is_refused_there_and_sent_elsewhere(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $threads = $this->connection($t, 'threads', 'th-1');
        $page = $this->connection($t, 'facebook_page', 'page-1');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-1'])]);

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$threads->id, $page->id], 'body' => str_repeat('a', 700),
        ])->assertCreated();

        $this->inTenant($t['org'], function () use ($threads) {
            $refused = SocialPostTarget::query()->where('social_connection_id', $threads->id)->sole();
            $this->assertSame(SocialPostTarget::STATUS_FAILED, $refused->status);
            $this->assertStringContainsString('500 characters at most and this post is 700', (string) $refused->failure);
        });
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'threads.net'));

        // Refused before anything is written, not by the job afterwards: with
        // Threads the only account, the whole post is a 422.
        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$threads->id], 'body' => str_repeat('a', 700),
        ])->assertStatus(422)->assertJsonValidationErrors('connections');
        $this->inTenant($t['org'], fn () => $this->assertSame(1, SocialPost::query()->count()));
    }

    public function test_threads_and_pinterest_refuse_rather_than_cut(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pin').'.png';
        file_put_contents($file, 'PNG');
        Http::fake();

        foreach ([
            [new ThreadsPublisher, SocialProvider::Threads, new PostPayload(body: str_repeat('a', 501))],
            [new PinterestPublisher, SocialProvider::Pinterest, new PostPayload(body: str_repeat('a', 801), filePath: $file)],
        ] as [$publisher, $provider, $payload]) {
            try {
                $publisher->publish($this->unsaved($provider), $payload);
                $this->fail($provider->label().' cut a long post instead of refusing it.');
            } catch (SocialPublishFailed $e) {
                $this->assertStringContainsString('characters at most', $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    /** #9: a words-only edit keeps the post's own clock; an unreadable time is a 422, not a 500. */
    public function test_editing_keeps_the_posts_timezone_and_refuses_an_unreadable_time(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'facebook_page', 'page-1');
        $id = $this->inTenant($t['org'], function () use ($t, $page) {
            $post = SocialPost::create([
                'body' => 'Autumn', 'created_by' => $t['admin']->id,
                'scheduled_for' => '2099-03-04 09:00:00', 'scheduled_timezone' => 'America/Los_Angeles',
            ]);
            $post->targets()->create(['social_connection_id' => $page->id, 'status' => 'scheduled']);

            return $post->id;
        });

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['scheduled_for' => 'tomorrow-ish'])
            ->assertStatus(422)->assertJsonValidationErrors('scheduled_for');

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['scheduled_for' => '2099-03-05 10:00'])->assertOk();

        $post = $this->inTenant($t['org'], fn () => SocialPost::query()->find($id));
        $this->assertSame('America/Los_Angeles', $post->scheduled_timezone);
        $this->assertStringStartsWith('2099-03-05 10:00', (string) $post->scheduled_for);
    }

    public function test_an_edit_obeys_the_same_body_rules_as_composing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $threads = $this->connection($t, 'threads', 'th-1');
        $id = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$threads->id], 'body' => 'Autumn', 'scheduled_for' => '2099-03-04 09:00',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['body' => str_repeat('a', 600)])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['body' => '  '])
            ->assertStatus(422)->assertJsonValidationErrors('body');
        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['body' => str_repeat('a', 5001)])
            ->assertStatus(422)->assertJsonValidationErrors('body');

        $this->assertSame('Autumn', $this->inTenant($t['org'], fn () => SocialPost::query()->find($id)->body));
    }

    /** #11: an account refused at compose does not pin the rest of a scheduled post. */
    public function test_a_scheduled_post_with_one_refused_account_can_still_be_edited_and_cancelled(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'facebook_page', 'page-1');
        $pin = $this->connection($t, 'pinterest', 'pin-1');
        Http::fake();

        $id = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$page->id, $pin->id], 'body' => 'Words only', 'scheduled_for' => '2099-03-04 09:00',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['body' => 'Better words'])->assertOk();
        $this->signedInAs($t['admin'])->deleteJson(self::ENDPOINT.'/'.$id)->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertSame(0, SocialPostTarget::query()->count()));
        Http::assertNothingSent();
    }

    /** #12: Try again on a post whose time has not come puts it back to wait for that time. */
    public function test_try_again_before_the_posts_time_waits_for_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'facebook_page', 'page-1');
        Http::fake();
        [$post, $target] = $this->inTenant($t['org'], function () use ($t, $page) {
            $post = SocialPost::create([
                'body' => 'Tuesday', 'created_by' => $t['admin']->id,
                'scheduled_for' => '2099-03-04 09:00:00', 'scheduled_timezone' => 'America/New_York',
            ]);

            return [$post->id, $post->targets()->create([
                'social_connection_id' => $page->id, 'status' => 'failed', 'failure' => 'Not live',
            ])->id];
        });

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$post}/targets/{$target}/retry")
            ->assertOk()->assertJsonPath('data.status', SocialPostTarget::STATUS_SCHEDULED);

        Http::assertNothingSent();
    }

    private function connection(array $t, string $provider, string $remoteId): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => $provider, 'remote_id' => $remoteId, 'display_name' => 'A-1 '.$provider,
            ]);
            $row->connect(['refresh_token' => 'r', 'access_token' => 'a']);
            $row->save();

            return $row;
        });
    }

    private function unsaved(SocialProvider $provider): SocialConnection
    {
        $row = new SocialConnection;
        $row->forceFill(['provider' => $provider->value, 'remote_id' => 'r-1', 'display_name' => 'Test']);
        $row->connect(['refresh_token' => 'r', 'access_token' => 'a']);

        return $row;
    }
}
