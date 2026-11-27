<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Models\SocialPostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Profile or inbox, chosen per post in the composer and stored as the
 * target's `mode`. The publisher follows the post, not the installation, and
 * falls back to the installation only when the post said nothing.
 */
class TikTokInboxModeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Http::preventStrayRequests();
    }

    public function test_inbox_mode_sends_a_draft_even_where_direct_post_is_the_default(): void
    {
        config(['services.tiktok.direct_post' => true]);
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok('SEND_TO_USER_INBOX');

        // No privacy: TikTok ignores it for a draft, so it is not asked.
        $data = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['mode' => 'inbox']],
        ])->assertSuccessful()->json('data');

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame('inbox', $target->options['mode']);
        $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status, (string) $target->failure);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/post/publish/inbox/video/init/'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/post/publish/video/init/'));

        $row = $data['targets'][0];
        $this->assertSame('Sent to TikTok inbox', $row['status_label']);
    }

    public function test_direct_mode_posts_to_the_profile_even_where_the_inbox_is_the_default(): void
    {
        config(['services.tiktok.direct_post' => false]);
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok('PUBLISH_COMPLETE');

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['mode' => 'direct', 'privacy_level' => 'SELF_ONLY']],
        ])->assertSuccessful();

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame('direct', $target->options['mode']);
        $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status, (string) $target->failure);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/post/publish/video/init/')
            && $r->data()['post_info']['privacy_level'] === 'SELF_ONLY');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/post/publish/inbox/video/init/'));
    }

    public function test_direct_mode_still_needs_a_privacy_choice(): void
    {
        config(['services.tiktok.direct_post' => false]);
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        Http::fake();

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['mode' => 'direct']],
        ])->assertStatus(422)->assertJsonValidationErrors("tiktok.$key.privacy_level");

        Http::assertNothingSent();
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        Http::fake();

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
            'tiktok' => [$key => ['mode' => 'drafts', 'privacy_level' => 'SELF_ONLY']],
        ])->assertStatus(422)->assertJsonValidationErrors("tiktok.$key.mode");

        $this->inTenant($t['org'], fn () => $this->assertSame(0, SocialPostTarget::query()->count()));
        Http::assertNothingSent();
    }

    public function test_status_polling_counts_the_inbox_as_sent(): void
    {
        config(['services.tiktok.direct_post' => false]);
        $t = $this->provisionTenant('A1-Group');
        $key = $this->tiktok($t)->getKey();
        $this->fakeTikTok('SEND_TO_USER_INBOX');

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$key],
            'media' => UploadedFile::fake()->create('clip.mp4', 12, 'video/mp4'),
        ])->assertSuccessful();

        $target = $this->inTenant($t['org'], fn () => SocialPostTarget::query()->sole());
        $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status, (string) $target->failure);
        $this->assertSame('pub-7', $target->remote_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/post/publish/status/fetch/'));
    }

    private function fakeTikTok(string $status): void
    {
        $init = Http::response([
            'data' => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-7'],
            'error' => ['code' => 'ok'],
        ]);

        Http::fake([
            'open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => $status], 'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/inbox/video/init/' => $init,
            'open.tiktokapis.com/v2/post/publish/video/init/' => $init,
            'upload.tiktok.test/put' => Http::response('', 200),
        ]);
    }

    private function tiktok(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'provider' => 'tiktok',
                'remote_id' => 'open-1',
                'display_name' => 'EzBizi US',
            ]);
            $row->connect(['refresh_token' => 'rft.old', 'access_token' => 'act.old']);
            $row->save();

            return $row;
        });
    }
}
