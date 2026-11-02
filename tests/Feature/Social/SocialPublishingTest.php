<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\FacebookPublisher;
use App\Support\Social\Publishing\InstagramPublisher;
use App\Support\Social\Publishing\MediaHandoff;
use App\Support\Social\Publishing\PinterestPublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\StillProcessing;
use App\Support\Social\Publishing\ThreadsPublisher;
use App\Support\Social\Publishing\TikTokPublisher;
use App\Support\Social\Publishing\YouTubePublisher;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            $this->assertStringContainsString('Attach', (string) $p->refuses(SocialMediaKind::Text));
        }

        foreach ([SocialProvider::FacebookPage, SocialProvider::Threads, SocialProvider::LinkedIn] as $p) {
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
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-9']),
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
                'data' => ['privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-7'],
                'error' => ['code' => 'ok'],
            ]),
            'upload.tiktok.test/put' => Http::response('', 200),
        ]);

        $id = (new TikTokPublisher)->publish(
            $this->connection(SocialProvider::TikTok),
            new PostPayload(body: 'Hello', filePath: $file, options: ['privacy_level' => 'SELF_ONLY']),
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
            new PostPayload(body: 'hello', filePath: $this->temp('mp4', 'VIDEOBYTES'), options: ['privacy_level' => 'SELF_ONLY']),
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
        $this->assertStringContainsString('few minutes', (string) Publishers::postingNoteFor(SocialProvider::TikTok));

        // A network that holds no surprise says nothing, rather than reassuring.
        $this->assertNull(Publishers::postingNoteFor(SocialProvider::Pinterest));
    }

    private function fakeTikTok(): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => ['privacy_level_options' => ['SELF_ONLY']],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/inbox/video/init/' => Http::response([
                'data' => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-1'],
                'error' => ['code' => 'ok'],
            ]),
            'open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => ['upload_url' => 'https://upload.tiktok.test/put', 'publish_id' => 'pub-2'],
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
            'graph.threads.net/*/threads' => Http::response(['id' => 'container-1']),
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
            'graph.threads.net/*/threads' => Http::response(['id' => 'container-1']),
        ]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/publishing it returned nothing/');

        (new ThreadsPublisher)->publish(
            $this->connection(SocialProvider::Threads, remoteId: 'user-1'),
            new PostPayload(body: 'Hello'),
        );
    }

    public function test_threads_no_longer_says_media_is_unbuilt(): void
    {
        // It said so until 2026-09-01, when MediaHandoff gave it somewhere to
        // point image_url at. Left as a test rather than deleted: "we do send
        // this now" is worth one assertion, because the wording it replaced is
        // still the right answer for any network we have not built.
        foreach (SocialMediaKind::cases() as $kind) {
            $this->assertNull((new ThreadsPublisher)->cannotSend($kind), $kind->value);
            $this->assertNull((new FacebookPublisher)->cannotSend($kind), $kind->value);
        }

        $this->assertNotNull((new InstagramPublisher)->cannotSend(SocialMediaKind::Text));
    }

    public function test_instagram_hands_the_photo_over_at_a_signed_url_and_takes_it_back(): void
    {
        $this->reachable();
        $file = $this->temp('jpg', 'JPEGBYTES');

        Http::fake([
            'graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig-post-1']),
            'graph.instagram.com/*/media' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $id = (new InstagramPublisher(pollSeconds: 0))->publish(
            $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
            new PostPayload(body: 'Swept and safe.', filePath: $file),
        );

        $this->assertSame('ig-post-1', $id);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/media')) {
                return false;
            }

            $url = (string) ($request->data()['image_url'] ?? '');

            // The handoff, and everything that makes it narrow: our own host,
            // a signature, and an expiry. A bare public path would satisfy
            // Instagram and would be the thing this was careful not to build.
            return str_starts_with($url, 'https://app.ezbizi.com/api/v1/social/media/')
                && str_contains($url, 'signature=')
                && str_contains($url, 'expires=')
                && ! isset($request->data()['video_url']);
        });

        // And it is gone. The window is the length of one fetch, not the hour
        // it was signed for - the signature is the ceiling, this is the plan.
        $this->assertSame([], Storage::disk('local')->allFiles('social-handoff'));
    }

    public function test_instagram_posts_video_as_a_reel_and_waits_before_publishing(): void
    {
        $this->reachable();
        $file = $this->temp('mp4', 'MP4BYTES');

        Http::fake([
            'graph.instagram.com/*/media_publish' => Http::response(['id' => 'ig-reel-1']),
            'graph.instagram.com/*/media' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*' => Http::response(['status_code' => 'FINISHED']),
        ]);

        $id = (new InstagramPublisher(pollSeconds: 0))->publish(
            $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
            new PostPayload(body: 'A reel', filePath: $file),
        );

        $this->assertSame('ig-reel-1', $id);

        // The wait sits between the two, and that ordering is the whole point:
        // publishing a container Instagram has not finished ingesting is
        // refused, which would break every video and no photo.
        Http::assertSentInOrder([
            fn ($r) => str_ends_with($r->url(), '/media')
                && $r->data()['media_type'] === 'REELS'
                && ! isset($r->data()['image_url'])
                && str_contains((string) $r->data()['video_url'], '/api/v1/social/media/'),
            fn ($r) => str_contains($r->url(), 'container-1') && str_contains($r->url(), 'status_code'),
            fn ($r) => str_ends_with($r->url(), '/media_publish'),
        ]);
    }

    /**
     * 2026-10-15: Rodrigo's reel failed at the 36-second ceiling. A video still
     * ingesting is now handed on - StillProcessing, with the file still held so
     * Instagram can keep fetching it - instead of failed.
     */
    /** Facebook answers a video's link relative ("/reel/…/", measured 2026-10-15). */
    public function test_a_facebook_video_link_is_made_whole(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['permalink_url' => '/reel/1792223185059436/'])]);

        $this->assertSame(
            'https://www.facebook.com/reel/1792223185059436/',
            (new FacebookPublisher)->permalink($this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'), '1792223185059436'),
        );
    }

    public function test_a_pin_links_to_its_page(): void
    {
        $this->assertSame('https://www.pinterest.com/pin/1234567/',
            (new PinterestPublisher)->permalink($this->connection(SocialProvider::Pinterest), '1234567'));
    }

    public function test_a_slow_instagram_video_is_handed_on_not_failed(): void
    {
        $this->reachable();
        $file = $this->temp('mp4', 'MP4BYTES');

        Http::fake([
            'graph.instagram.com/*/media' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*' => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        try {
            (new InstagramPublisher(pollSeconds: 0))->publish(
                $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
                new PostPayload(body: 'A long reel', filePath: $file),
            );
            $this->fail('a video still processing should have been handed on');
        } catch (StillProcessing $e) {
            $this->assertSame('container-1', $e->container);
            $this->assertSame([MediaHandoff::pathFor($e->handle)], Storage::disk('local')->allFiles('social-handoff'));
        }

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/media_publish'));
    }

    public function test_finishing_publishes_only_once_instagram_is_done(): void
    {
        $connection = $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1');

        Http::fake(['graph.instagram.com/*' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS'])
            ->push(['status_code' => 'FINISHED'])
            ->push(['id' => 'ig-reel-9'])]);

        $this->assertNull((new InstagramPublisher)->finish($connection, 'container-1'));
        $this->assertSame('ig-reel-9', (new InstagramPublisher)->finish($connection, 'container-1'));
    }

    public function test_finishing_passes_on_instagrams_refusal(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['status_code' => 'ERROR', 'status' => 'Unsupported codec.'])]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/Unsupported codec/');

        (new InstagramPublisher)->finish($this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'), 'container-1');
    }

    public function test_instagram_passes_on_the_reason_the_media_was_rejected(): void
    {
        $this->reachable();
        $file = $this->temp('mp4', 'MP4BYTES');

        Http::fake([
            'graph.instagram.com/*/media' => Http::response(['id' => 'container-1']),
            'graph.instagram.com/*' => Http::response([
                'status_code' => 'ERROR',
                'status' => 'The video is longer than 15 minutes.',
            ]),
        ]);

        try {
            (new InstagramPublisher(pollSeconds: 0))->publish(
                $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
                new PostPayload(body: 'Too long', filePath: $file),
            );
            $this->fail('a rejected container should have thrown');
        } catch (SocialPublishFailed $e) {
            // Instagram's own sentence, not "publish failed". It names the fix.
            $this->assertStringContainsString('longer than 15 minutes', $e->getMessage());
        }

        // Released on the way out. A refused post is exactly when a tenant's
        // video must stop being fetchable, not a moment later.
        $this->assertSame([], Storage::disk('local')->allFiles('social-handoff'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/media_publish'));
    }

    public function test_instagram_releases_the_handoff_when_the_container_is_refused(): void
    {
        $this->reachable();
        $file = $this->temp('jpg', 'JPEGBYTES');

        Http::fake(['graph.instagram.com/*' => Http::response(
            ['error' => ['message' => 'Unsupported aspect ratio.']], 400
        )]);

        $this->expectException(SocialPublishFailed::class);

        try {
            (new InstagramPublisher(pollSeconds: 0))->publish(
                $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
                new PostPayload(body: 'Nope', filePath: $file),
            );
        } finally {
            $this->assertSame([], Storage::disk('local')->allFiles('social-handoff'));
        }
    }

    public function test_instagram_refuses_words_alone_before_anything_is_written(): void
    {
        $this->reachable();
        Http::fake();

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/does not post words on their own/');

        (new InstagramPublisher(pollSeconds: 0))->publish(
            $this->connection(SocialProvider::InstagramProfessional, remoteId: 'ig-1'),
            new PostPayload(body: 'Just words'),
        );
    }

    public function test_a_handoff_url_serves_the_file_and_an_unsigned_one_does_not(): void
    {
        $this->reachable();
        $handoff = MediaHandoff::hold(new PostPayload(filePath: $this->temp('png', 'PNGBYTES')));

        $signed = $this->get($handoff->url);
        $signed->assertOk();

        // Meta reads the type off the RESPONSE, not off the URL. Serving a good
        // PNG as octet-stream gets the container rejected for its format.
        $this->assertStringContainsString('image/png', (string) $signed->headers->get('Content-Type'));
        $this->assertSame('PNGBYTES', $signed->streamedContent());

        // Strip the signature and it is refused. The random name is not the
        // only thing standing between a stranger and a tenant's photo.
        $this->get(strtok($handoff->url, '?'))->assertStatus(403);

        $handoff->release();
        $this->get($handoff->url)->assertNotFound();
    }

    public function test_a_server_publishing_links_to_localhost_says_so_instead_of_blaming_the_photo(): void
    {
        // APP_URL ships as http://localhost and every .env starts life that
        // way. Meta would fetch the loopback, reach nothing, and report a media
        // error - so the tenant would be told their picture was the problem.
        URL::forceRootUrl('http://localhost');

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/APP_URL/');

        MediaHandoff::hold(new PostPayload(filePath: $this->temp('png', 'PNGBYTES')));
    }

    public function test_a_handoff_must_be_https_even_on_a_reachable_host(): void
    {
        // A reachable host is not enough. Over http the photo crosses the
        // internet in the clear, to a URL whose entire security - the
        // unguessable name and the signature - is in the query string an
        // eavesdropper is reading. There is no certificate worth saving.
        URL::forceScheme('http');
        URL::forceRootUrl('http://app.ezbizi.com');

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessageMatches('/https/');

        MediaHandoff::hold(new PostPayload(filePath: $this->temp('png', 'PNGBYTES')));
    }

    public function test_the_sweep_only_takes_handoffs_that_already_stopped_working(): void
    {
        $this->reachable();

        $fresh = MediaHandoff::hold(new PostPayload(filePath: $this->temp('png', 'A')));
        $stale = MediaHandoff::hold(new PostPayload(filePath: $this->temp('png', 'B')));

        touch(
            Storage::disk('local')->path(MediaHandoff::pathFor($stale->handle)),
            now()->subMinutes(MediaHandoff::MINUTES + 30)->getTimestamp(),
        );

        $this->assertSame(1, MediaHandoff::sweep());
        $this->assertTrue(Storage::disk('local')->exists(MediaHandoff::pathFor($fresh->handle)));
        $this->assertFalse(Storage::disk('local')->exists(MediaHandoff::pathFor($stale->handle)));
    }

    public function test_facebook_posts_a_video_by_url_and_calls_the_words_a_description(): void
    {
        $this->reachable();
        $file = $this->temp('mp4', 'MP4BYTES');

        Http::fake(['graph.facebook.com/*/videos' => Http::response(['id' => 'video-1'])]);

        $id = (new FacebookPublisher)->publish(
            $this->connection(SocialProvider::FacebookPage, remoteId: 'page-1'),
            new PostPayload(body: 'A chimney sweep', filePath: $file),
        );

        $this->assertSame('video-1', $id);

        Http::assertSent(function ($request) {
            // `description` - not `message` (feed) and not `caption` (photos).
            // Facebook accepts the wrong one and silently drops the words, so
            // the post succeeds missing half of itself.
            return str_ends_with($request->url(), '/videos')
                && $request->data()['description'] === 'A chimney sweep'
                && str_contains((string) $request->data()['file_url'], '/api/v1/social/media/');
        });

        $this->assertSame([], Storage::disk('local')->allFiles('social-handoff'));
    }

    public function test_threads_posts_a_picture_as_an_image_container(): void
    {
        $this->reachable();
        $file = $this->temp('jpg', 'JPEGBYTES');

        Http::fake([
            'graph.threads.net/*/threads_publish' => Http::response(['id' => 'thread-9']),
            'graph.threads.net/*/threads' => Http::response(['id' => 'container-1']),
        ]);

        $id = (new ThreadsPublisher(pollSeconds: 0))->publish(
            $this->connection(SocialProvider::Threads, remoteId: 'user-1'),
            new PostPayload(body: 'With a picture', filePath: $file),
        );

        $this->assertSame('thread-9', $id);

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/threads')
                && $request->data()['media_type'] === 'IMAGE'
                && $request->data()['text'] === 'With a picture'
                && str_contains((string) $request->data()['image_url'], '/api/v1/social/media/');
        });

        // An image container is NOT polled - only video is ingested
        // asynchronously, and polling a ready container would be a wasted round
        // trip on every photo anybody posts.
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'fields=status'));

        $this->assertSame([], Storage::disk('local')->allFiles('social-handoff'));
    }

    public function test_instagram_now_has_a_publisher_and_the_map_says_so(): void
    {
        $this->assertNotNull(Publishers::for(SocialProvider::InstagramProfessional));
        $this->assertNull(Publishers::refusalFor(SocialProvider::InstagramProfessional));
    }

    /**
     * Every extension the composer accepts has a type, and nothing is guessed.
     *
     * The loop is the guard, not the decoration: the upload rule is built from
     * SocialMediaKind::extensions(), so an extension added there and forgotten
     * here used to be served as `video/mp4` - which is how an iPhone .heic
     * photo reached Instagram as a video.
     */
    public function test_every_accepted_extension_has_its_own_type_and_nothing_is_guessed(): void
    {
        foreach (SocialMediaKind::extensions() as $extension) {
            $mime = PostPayload::mimeForExtension($extension);

            $expected = SocialMediaKind::forFile('x.'.$extension) === SocialMediaKind::Image ? 'image/' : 'video/';
            $this->assertStringStartsWith($expected, $mime, $extension.' is served as '.$mime);
        }

        $this->assertSame('image/heic', PostPayload::mimeForExtension('HEIC'));
        $this->assertSame('image/heif', PostPayload::mimeForExtension('heif'));
        $this->assertSame('video/mp4', PostPayload::mimeForExtension('mp4'));
        $this->assertSame('video/mp4', PostPayload::mimeForExtension('m4v'));
        $this->assertSame('application/pdf', PostPayload::mimeForExtension('pdf'));

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessage('.tiff');
        PostPayload::mimeForExtension('tiff');
    }

    public function test_a_handoff_serves_a_heic_photo_as_a_photo(): void
    {
        $this->reachable();

        $handoff = MediaHandoff::hold(new PostPayload(filePath: $this->temp('heic', 'HEICBYTES')));

        $this->get($handoff->url)->assertOk()->assertHeader('Content-Type', 'image/heic');

        $handoff->release();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Handoffs are written to the private disk. Faking it keeps a test run
        // from leaving a tenant's picture in storage/app/private.
        Storage::fake('local');
    }

    /**
     * A server whose signed URLs a network could actually fetch.
     *
     * The suite's APP_URL is http://localhost, which MediaHandoff refuses on
     * purpose - so every test that hands media over has to say what the
     * server's real address is, exactly as production does.
     */
    private function reachable(): void
    {
        URL::forceScheme('https');
        URL::forceRootUrl('https://app.ezbizi.com');
    }

    private function temp(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'social').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }

    /** Delete from the network (2026-10-15). */
    public function test_youtube_deletes_the_video_it_published(): void
    {
        config(['services.youtube.client_id' => 'cid', 'services.youtube.client_secret' => 'sec']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]), '*' => Http::response(null, 204)]);

        (new YouTubePublisher)->deleteRemote($this->connection(SocialProvider::YouTube), 'vid-9');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/youtube/v3/videos?id=vid-9'));
    }

    public function test_pinterest_deletes_the_pin_it_published(): void
    {
        Http::fake();

        (new PinterestPublisher)->deleteRemote($this->connection(SocialProvider::Pinterest, 'pin-account'), 'pin-9');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v5/pins/pin-9'));
    }

    private function connection(SocialProvider $provider, string $remoteId = 'remote-1'): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider' => $provider->value,
            'remote_id' => $remoteId,
            'display_name' => 'Test account',
        ]);
        $connection->connect(['refresh_token' => 'r', 'access_token' => 'a-token']);

        return $connection;
    }
}
