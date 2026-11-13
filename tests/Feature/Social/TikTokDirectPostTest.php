<?php

namespace Tests\Feature\Social;

use App\Jobs\FinishTikTokPost;
use App\Models\SocialConnection;
use App\Models\SocialPostTarget;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\TikTokPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * TikTok Direct Post as its content-sharing guidelines require: the person
 * picks privacy (no default), comment/duet/stitch (off unless ticked) and
 * disclosure every time; EzBizi sends exactly that. And a 24-hour access token
 * is renewed rather than left to die, which is what the only live connection
 * had done since 2026-08-29.
 */
class TikTokDirectPostTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        config(['services.tiktok.direct_post' => true]);
    }

    public function test_the_composers_choices_are_what_tiktok_receives(): void
    {
        $this->fakeTikTok(creator: ['duet_disabled' => true]);

        (new TikTokPublisher)->publish($this->unsaved(), new PostPayload(
            body: 'Chimney day',
            filePath: $this->video(),
            options: [
                'privacy_level' => 'PUBLIC_TO_EVERYONE',
                'allow_comment' => true,
                'allow_duet' => true,
                'allow_stitch' => false,
                'your_brand' => true,
                'branded_content' => false,
            ],
        ));

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/post/publish/video/init/')) {
                return false;
            }

            $info = $request->data()['post_info'];

            return $info['title'] === 'Chimney day'
                && $info['privacy_level'] === 'PUBLIC_TO_EVERYONE'
                && $info['disable_comment'] === false
                // Ticked, but the account has duets switched off.
                && $info['disable_duet'] === true
                && $info['disable_stitch'] === true
                && $info['brand_organic_toggle'] === true
                && $info['brand_content_toggle'] === false;
        });
    }

    public function test_nothing_is_published_at_a_visibility_nobody_chose(): void
    {
        $this->fakeTikTok();

        foreach ([[], ['privacy_level' => 'FOLLOWER_OF_CREATOR']] as $options) {
            try {
                (new TikTokPublisher)->publish($this->unsaved(), new PostPayload(filePath: $this->video(), options: $options));
                $this->fail('Published without a privacy level the creator offers.');
            } catch (SocialPublishFailed $e) {
                $this->assertStringContainsString('who can see', $e->getMessage());
            }
        }

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/post/publish/video/init/'));
    }

    public function test_an_expired_token_is_renewed_stored_and_used(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->tiktok($t, expiresAt: now()->subDay()->toIso8601String());

        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'act.new', 'refresh_token' => 'rft.new', 'expires_in' => 86400,
            ]),
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => ['privacy_level_options' => ['SELF_ONLY']], 'error' => ['code' => 'ok'],
            ]),
        ]);

        $this->inTenant($t['org'], fn () => (new TikTokPublisher)->creatorInfo((new TikTokPublisher)->token($row)));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/token/')
            && $r->data()['grant_type'] === 'refresh_token'
            && $r->data()['refresh_token'] === 'rft.old');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'creator_info')
            && $r->header('Authorization')[0] === 'Bearer act.new');

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame('act.new', $saved->credentials['access_token']);
        $this->assertSame('rft.new', $saved->credentials['refresh_token']);
        $this->assertTrue(now()->addHours(23)->lt($saved->credentials['expires_at']));
    }

    public function test_a_renewed_token_rereads_the_expiring_avatar_url(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->tiktok($t, expiresAt: now()->subDay()->toIso8601String());
        $this->inTenant($t['org'], fn () => $row->forceFill(['avatar_url' => 'https://p16.tiktokcdn.test/old.jpeg'])->save());

        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'act.new', 'refresh_token' => 'rft.new', 'expires_in' => 86400,
            ]),
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => [
                'open_id' => 'open-1', 'display_name' => 'EzBizi US', 'avatar_url' => 'https://p16.tiktokcdn.test/new.jpeg',
            ]]]),
        ]);

        $this->inTenant($t['org'], fn () => (new TikTokPublisher)->token($row));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/user/info/')
            && $r->header('Authorization')[0] === 'Bearer act.new');
        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame('https://p16.tiktokcdn.test/new.jpeg', $saved->avatar_url);
    }

    public function test_a_live_token_is_not_renewed(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->tiktok($t, expiresAt: now()->addHours(5)->toIso8601String());
        Http::fake();

        $this->assertSame('act.old', (new TikTokPublisher)->token($row));
        Http::assertNothingSent();
    }

    public function test_a_refused_renewal_marks_the_connection_for_reconnecting(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->tiktok($t, expiresAt: now()->subDay()->toIso8601String());
        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->inTenant($t['org'], fn () => (new TikTokPublisher)->token($row));
            $this->fail('A refused renewal returned a token.');
        } catch (SocialPublishFailed) {
        }

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    public function test_the_composer_must_choose_privacy_and_the_choice_reaches_tiktok(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->tiktok($t);
        $this->fakeTikTok();
        $key = $row->getKey();

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
        ])->assertStatus(422)->assertJsonValidationErrors("tiktok.$key.privacy_level");

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['privacy_level' => 'SELF_ONLY', 'allow_comment' => '1']],
        ])->assertSuccessful();

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame('SELF_ONLY', $target->options['privacy_level']);
        $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status, (string) $target->failure);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/post/publish/video/init/')
            && $r->data()['post_info']['privacy_level'] === 'SELF_ONLY'
            && $r->data()['post_info']['disable_comment'] === false
            && $r->data()['post_info']['disable_stitch'] === true);
    }

    public function test_disclosure_needs_a_kind_and_branded_content_cannot_be_private(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        Http::fake();

        foreach ([
            ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'disclose' => '1'],
            ['privacy_level' => 'SELF_ONLY', 'disclose' => '1', 'branded_content' => '1'],
        ] as $choice) {
            $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
                'connections' => [$key],
                'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
                'tiktok' => [$key => $choice],
            ])->assertStatus(422)->assertJsonValidationErrors("tiktok.$key");
        }

        Http::assertNothingSent();
    }

    public function test_the_creator_panel_reads_what_the_account_allows(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok(creator: ['creator_nickname' => 'EzBizi US', 'stitch_disabled' => true, 'max_video_post_duration_sec' => 600]);

        $this->signedInAs($t['admin'])->getJson("/api/v1/social/connections/$key/tiktok-creator")
            ->assertOk()
            ->assertJsonPath('data.nickname', 'EzBizi US')
            ->assertJsonPath('data.privacy_level_options', ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'])
            ->assertJsonPath('data.stitch_disabled', true)
            ->assertJsonPath('data.max_video_post_duration_sec', 600);

        $reader = $this->userWithRole($t, 'Reader', ['marketing.view', 'marketing.compose.view']);
        $this->signedInAs($reader)->getJson("/api/v1/social/connections/$key/tiktok-creator")->assertForbidden();
    }

    /**
     * Bug-review #5 (2026-11-09): the upload only queues TikTok's work, so the
     * post is Sent when TikTok says PUBLISH_COMPLETE, after looking again while
     * it processes - and Failed, with TikTok's reason, when it refuses.
     */
    public function test_a_tiktok_post_is_sent_only_once_tiktok_has_published_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok(status: [
            ['status' => 'PROCESSING_UPLOAD'],
            ['status' => 'PUBLISH_COMPLETE'],
        ]);

        $this->postVideo($t, $key);

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status, (string) $target->failure);
        $this->assertSame('pub-9', $target->remote_id);
        Http::assertSentCount(5); // creator_info, init, upload, two status looks
    }

    public function test_a_video_tiktok_refuses_while_processing_is_failed_with_its_reason(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok(status: [['status' => 'FAILED', 'fail_reason' => 'video_pull_failed']]);

        $this->postVideo($t, $key);

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('video_pull_failed', (string) $target->failure);
    }

    public function test_tiktok_still_processing_after_the_last_look_is_failed_not_left_queued(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok(status: [['status' => 'PROCESSING_UPLOAD']]);

        $this->postVideo($t, $key);

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame(SocialPostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsString('still processing', (string) $target->failure);
        Http::assertSentCount(3 + FinishTikTokPost::LOOKS);
    }

    private function postVideo(array $t, string $key): void
    {
        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['privacy_level' => 'SELF_ONLY']],
        ])->assertSuccessful();
    }

    /**
     * @param  array<string, mixed>  $creator
     * @param  list<array<string, mixed>>  $status  what status/fetch answers, in order (the last repeats)
     */
    private function fakeTikTok(array $creator = [], array $status = [['status' => 'PUBLISH_COMPLETE']]): void
    {
        $looks = Http::sequence();
        foreach ($status as $answer) {
            $looks->push(['data' => $answer, 'error' => ['code' => 'ok']]);
        }
        $looks->whenEmpty(Http::response(['data' => end($status), 'error' => ['code' => 'ok']]));

        Http::fake([
            'open.tiktokapis.com/v2/post/publish/status/fetch/' => $looks,
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => $creator + ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-9'],
                'error' => ['code' => 'ok'],
            ]),
            'upload.tiktok.test/put' => Http::response('', 200),
        ]);
    }

    private function unsaved(): SocialConnection
    {
        $row = new SocialConnection;
        $row->forceFill(['provider' => 'tiktok', 'remote_id' => 'open-1', 'display_name' => 'EzBizi US']);
        $row->connect(['refresh_token' => 'rft.old', 'access_token' => 'act.old']);

        return $row;
    }

    private function tiktok(array $t, ?string $expiresAt = null): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $expiresAt) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'provider' => 'tiktok',
                'remote_id' => 'open-1',
                'display_name' => 'EzBizi US',
            ]);
            $row->connect(array_filter([
                'refresh_token' => 'rft.old',
                'access_token' => 'act.old',
                'expires_at' => $expiresAt,
            ]));
            $row->save();

            return $row;
        });
    }

    private function video(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tt').'.mp4';
        file_put_contents($path, 'VIDEOBYTES');

        return $path;
    }
}
