<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\FacebookPublisher;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\ThreadsPublisher;
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
        $this->assertSame(SocialMediaKind::Image, (new PostPayload(filePath: '/tmp/a.PNG'))->kind());
        $this->assertSame(SocialMediaKind::Video, (new PostPayload(filePath: '/tmp/a.mp4'))->kind());
        $this->assertSame(SocialMediaKind::Text, (new PostPayload(body: 'hello'))->kind());
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
            new PostPayload(body: 'Hello', filePath: $file, title: 'EzBizi'),
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
            new PostPayload(filePath: $file),
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
            new PostPayload(body: 'Hello', filePath: $file),
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
            new PostPayload(filePath: $file),
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
            new PostPayload(filePath: $file),
        );
    }

    /**
     * The deadlock that put "Please review our integration guidelines" on a
     * customer's screen, and the assertion that keeps it broken.
     *
     * Direct Post requires TikTok's audit. The audit submission requires a
     * filmed demo of a working publish. So on an unaudited installation the
     * composer MUST take the inbox, or there is no publish to film and the
     * circle never opens. `Publishers::for()` defaulted to Direct Post, which
     * is why every TikTok post from the UI was refused on 2026-08-28.
     *
     * The assertion is on WHICH ENDPOINT is called, because that is the whole
     * difference — both paths upload the same bytes and both answer a
     * publish_id, so nothing else distinguishes them.
     */
    public function test_an_unaudited_installation_posts_to_the_tiktok_inbox_and_not_the_profile(): void
    {
        config(['services.tiktok.direct_post' => false]);

        $this->fakeTikTok();

        Publishers::for(SocialProvider::TikTok)->publish(
            $this->connection(SocialProvider::TikTok),
            new PostPayload(body: 'hello', filePath: $this->temp('mp4', 'VIDEOBYTES')),
        );

        Http::assertSent(fn ($r) => str_contains($r->url(), '/post/publish/inbox/video/init/'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/post/publish/video/init/'));
    }

    public function test_an_audited_installation_posts_straight_to_the_profile(): void
    {
        config(['services.tiktok.direct_post' => true]);

        $this->fakeTikTok();

        Publishers::for(SocialProvider::TikTok)->publish(
            $this->connection(SocialProvider::TikTok),
            new PostPayload(body: 'hello', filePath: $this->temp('mp4', 'VIDEOBYTES')),
        );

        // And it asks what the creator allows first, which is the thing TikTok
        // refuses the post for skipping.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/creator_info/query/'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/post/publish/video/init/'));
    }

    /**
     * The screen is told before anybody presses Publish, not after.
     *
     * "Sent" and "sent, but you must open TikTok and finish it" are different
     * enough that learning it afterwards is learning it too late.
     */
    public function test_the_drafts_detour_is_declared_and_disappears_once_audited(): void
    {
        config(['services.tiktok.direct_post' => false]);
        $this->assertStringContainsString(
            'drafts',
            (string) Publishers::postingNoteFor(SocialProvider::TikTok),
        );

        config(['services.tiktok.direct_post' => true]);
        $this->assertNull(Publishers::postingNoteFor(SocialProvider::TikTok));

        // A network that holds no surprise says nothing, rather than reassuring.
        $this->assertNull(Publishers::postingNoteFor(SocialProvider::Pinterest));
    }

    private function fakeTikTok(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data'  => ['privacy_level_options' => ['SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/inbox/video/init/' => Http::response([
                'data'  => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-1'],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data'  => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-2'],
                'error' => ['code' => 'ok'],
            ]),
            'upload.tiktok.test/put' => Http::response('', 200),
        ]);
    }

    public function test_facebook_posts_words_to_the_pages_feed_with_the_page_token(): void
    {
        Http::fake(['graph.facebook.com/*/feed' => Http::response(['id' => 'page_1_post_2'])]);

        $id = (new FacebookPublisher)->publish(
            $this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'),
            new PostPayload(body: 'Swept and safe.'),
        );

        $this->assertSame('page_1_post_2', $id);

        Http::assertSent(function ($request) {
            // The PAGE id in the path and the PAGE token in the body. A user
            // token here fails with a permissions error that reads like a
            // missing scope, which sends you looking in the wrong place.
            return str_contains($request->url(), '/page-1/feed')
                && $request->data()['message'] === 'Swept and safe.'
                && $request->data()['access_token'] === 'a-token';
        });
    }

    public function test_facebook_sends_an_image_as_bytes_rather_than_a_public_url(): void
    {
        $file = $this->temp('png', 'PNGBYTES');

        Http::fake(['graph.facebook.com/*/photos' => Http::response(['id' => 'photo-1', 'post_id' => 'page_1_post_9'])]);

        $id = (new FacebookPublisher)->publish(
            $this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'),
            new PostPayload(body: 'A caption', filePath: $file),
        );

        // post_id, not the photo's own id: that is what identifies the thing a
        // person would actually see on the Page.
        $this->assertSame('page_1_post_9', $id);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/photos')) {
                return false;
            }

            // Multipart with the bytes attached, and no `url` field. Sending a
            // url would mean publishing tenant media to an unauthenticated
            // public address, which is the trade Pinterest refuses too.
            $names = array_column($request->data(), 'name');

            return $request->isMultipart()
                && in_array('source', $names, true)
                && ! in_array('url', $names, true);
        });
    }

    public function test_facebook_refuses_an_empty_post_before_it_reaches_the_network(): void
    {
        Http::fake();

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/empty post/');

        (new FacebookPublisher)->publish(
            $this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'),
            new PostPayload(body: '   '),
        );
    }

    public function test_facebook_passes_through_the_sentence_graph_returned(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(
            ['error' => ['message' => 'The page is restricted.']], 400
        )]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/page is restricted/');

        (new FacebookPublisher)->publish(
            $this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'),
            new PostPayload(body: 'Hello'),
        );
    }

    public function test_threads_creates_a_container_then_publishes_it(): void
    {
        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response(['id' => 'thread-7']),
            'graph.threads.net/*/threads'         => Http::response(['id' => 'container-1']),
        ]);

        $id = (new ThreadsPublisher)->publish(
            $this->connection(SocialProvider::Threads, remoteId: 'user-1'),
            new PostPayload(body: 'Hello Threads'),
        );

        $this->assertSame('thread-7', $id);

        // BOTH calls, in order. A container that is never published is the
        // failure that looks like success: 200 back, nothing on the profile.
        Http::assertSentInOrder([
            fn ($r) => str_contains($r->url(), '/user-1/threads') && $r->data()['media_type'] === 'TEXT',
            fn ($r) => str_contains($r->url(), '/user-1/threads_publish') && $r->data()['creation_id'] === 'container-1',
        ]);
    }

    public function test_threads_fails_loudly_when_the_container_is_never_published(): void
    {
        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response([]),
            'graph.threads.net/*/threads'         => Http::response(['id' => 'container-1']),
        ]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/publishing it returned nothing/');

        (new ThreadsPublisher)->publish(
            $this->connection(SocialProvider::Threads, remoteId: 'user-1'),
            new PostPayload(body: 'Hello'),
        );
    }

    public function test_threads_says_media_is_not_built_rather_than_not_accepted(): void
    {
        // Threads takes images perfectly well; EzBizi does not send them,
        // because that needs a public image_url. The wording has to separate
        // "we have not built it" from "they refuse it".
        $why = (new ThreadsPublisher)->cannotSend(SocialMediaKind::Image);

        $this->assertNotNull($why);
        $this->assertNull((new ThreadsPublisher)->cannotSend(SocialMediaKind::Text));
    }

    public function test_instagram_has_no_publisher_and_says_so_honestly(): void
    {
        // Deliberate: every Instagram publish path needs a public image_url or
        // video_url, which is a privacy decision nobody has made. Falling
        // through to null gives the honest "the poster is not built" rather
        // than a class whose only job is to refuse.
        $this->assertNull(Publishers::for(SocialProvider::InstagramProfessional));
        $this->assertNotNull(Publishers::for(SocialProvider::FacebookPage));
        $this->assertNotNull(Publishers::for(SocialProvider::Threads));
    }

    private function temp(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'social').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }

    private function connection(SocialProvider $provider, string $remoteId = 'remote-1'): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider'     => $provider->value,
            'remote_id'    => $remoteId,
            'display_name' => 'Test account',
        ]);
        $connection->connect(['refresh_token' => 'r', 'access_token' => 'a-token']);

        return $connection;
    }
}
