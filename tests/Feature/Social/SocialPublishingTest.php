<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\SocialPost;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What each network will take, and the two publishers that exist.
 *
 * The network calls are faked. What is pinned here is the SHAPE of the request —
 * Pinterest's base64 media_source, TikTok's inclusive Content-Range — because
 * those are the parts a live send only tells you about once, in an error that
 * does not say which of them was wrong.
 */
class SocialPublishingTest extends TestCase
{
    public function test_a_network_that_needs_media_refuses_words_alone(): void
    {
        foreach ([SocialProvider::Pinterest, SocialProvider::TikTok, SocialProvider::InstagramProfessional] as $p) {
            $this->assertTrue($p->requiresMedia(), $p->value.' should need media');
            $this->assertStringContainsString('Attach', (string) $p->refuses(SocialMediaKind::Text));
        }

        foreach ([SocialProvider::FacebookPage, SocialProvider::Threads, SocialProvider::LinkedIn] as $p) {
            $this->assertFalse($p->requiresMedia(), $p->value.' posts words alone');
            $this->assertNull($p->refuses(SocialMediaKind::Text));
        }
    }

    public function test_youtube_takes_video_only_and_bluesky_takes_no_video(): void
    {
        $this->assertSame([SocialMediaKind::Video], SocialProvider::YouTube->accepts());
        $this->assertNotNull(SocialProvider::YouTube->refuses(SocialMediaKind::Image));

        $this->assertFalse(SocialProvider::Bluesky->takes(SocialMediaKind::Video));
        $this->assertSame('Bluesky does not take video.', SocialProvider::Bluesky->refuses(SocialMediaKind::Video));
    }

    public function test_a_file_decides_the_kind_by_extension(): void
    {
        $this->assertSame(SocialMediaKind::Image, (new SocialPost(filePath: '/tmp/a.PNG'))->kind());
        $this->assertSame(SocialMediaKind::Video, (new SocialPost(filePath: '/tmp/a.mp4'))->kind());
        $this->assertSame(SocialMediaKind::Text, (new SocialPost(body: 'hello'))->kind());
    }

    public function test_pinterest_puts_the_image_on_the_first_board_as_base64(): void
    {
        $file = $this->temp('png', 'PNGBYTES');

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins'    => Http::response(['id' => 'pin-9']),
        ]);

        $id = (new PinterestPublisher)->publish(
            $this->connection(SocialProvider::Pinterest),
            new SocialPost(body: 'Hello', filePath: $file, title: 'EzBizi'),
        );

        $this->assertSame('pin-9', $id);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/pins')) {
                return false;
            }

            $body = $request->data();

            return $body['board_id'] === 'board-1'
                && $body['media_source']['source_type'] === 'image_base64'
                && $body['media_source']['data'] === base64_encode('PNGBYTES');
        });
    }

    public function test_pinterest_says_so_when_the_account_has_no_boards(): void
    {
        $file = $this->temp('png', 'x');

        Http::fake(['api.pinterest.com/v5/boards*' => Http::response(['items' => []])]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/has no boards/');

        (new PinterestPublisher)->publish(
            $this->connection(SocialProvider::Pinterest),
            new SocialPost(filePath: $file),
        );
    }

    public function test_tiktok_uploads_the_whole_file_with_an_inclusive_content_range(): void
    {
        $file = $this->temp('mp4', str_repeat('V', 500));

        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data'  => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data'  => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-7'],
                'error' => ['code' => 'ok'],
            ]),
            'upload.tiktok.test/put' => Http::response('', 200),
        ]);

        $id = (new TikTokPublisher)->publish(
            $this->connection(SocialProvider::TikTok),
            new SocialPost(body: 'Hello', filePath: $file),
        );

        $this->assertSame('pub-7', $id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'upload.tiktok.test')) {
                return false;
            }

            // 0-499/500, not 0-500/500. The off-by-one is a bare 400.
            return $request->header('Content-Range')[0] === 'bytes 0-499/500';
        });

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'video/init')) {
                return false;
            }

            // Unaudited apps may only post privately, and asking for anything
            // else is refused in a way that reads like a caller bug.
            return $request->data()['post_info']['privacy_level'] === 'SELF_ONLY';
        });
    }

    public function test_tiktok_treats_an_error_inside_a_200_as_a_failure(): void
    {
        $file = $this->temp('mp4', 'V');

        Http::fake([
            'open.tiktokapis.com/*' => Http::response([
                'error' => ['code' => 'spam_risk_too_many_posts', 'message' => 'Daily post cap reached.'],
            ], 200),
        ]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/Daily post cap reached/');

        (new TikTokPublisher)->publish(
            $this->connection(SocialProvider::TikTok),
            new SocialPost(filePath: $file),
        );
    }

    public function test_a_publisher_refuses_a_kind_it_has_not_built(): void
    {
        // Pinterest takes video; EzBizi has not built it. Two different facts,
        // and the sentence has to say which one this is.
        $file = $this->temp('mp4', 'x');

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/poster is not built/');

        (new PinterestPublisher)->publish(
            $this->connection(SocialProvider::Pinterest),
            new SocialPost(filePath: $file),
        );
    }

    private function temp(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'social').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }

    private function connection(SocialProvider $provider): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider'     => $provider->value,
            'display_name' => 'Test account',
        ]);
        $connection->connect(['refresh_token' => 'r', 'access_token' => 'a-token']);

        return $connection;
    }
}
