<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\LinkedInPublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\YouTubePublisher;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * LinkedIn and YouTube, the two networks that got publishers on 2026-10-09.
 *
 * The network calls are faked. What is pinned is the SHAPE: LinkedIn's version
 * header and author URN, its upload-then-reference media and the ETags handed
 * back in order; YouTube's two-step resumable upload. Those are the parts a
 * live send only reports once, in an error that does not say which was wrong.
 */
class LinkedInAndYouTubePublishingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.linkedin.client_id' => 'li-client',
            'services.linkedin.client_secret' => 'li-secret',
            'services.youtube.client_id' => 'yt-client.apps.googleusercontent.com',
            'services.youtube.client_secret' => 'yt-secret',
        ]);
    }

    // ── The map ─────────────────────────────────────────────────

    public function test_both_networks_now_have_a_publisher_and_the_map_says_so(): void
    {
        $this->assertInstanceOf(LinkedInPublisher::class, Publishers::for(SocialProvider::LinkedIn));
        $this->assertInstanceOf(YouTubePublisher::class, Publishers::for(SocialProvider::YouTube));
        $this->assertNull(Publishers::refusalFor(SocialProvider::LinkedIn));
        $this->assertNull(Publishers::refusalFor(SocialProvider::YouTube));

        // Every network the catalogue offers can now be posted to.
        foreach (SocialProvider::all() as $provider) {
            $this->assertNull(Publishers::refusalFor($provider), $provider->value);
        }
    }

    public function test_what_each_publisher_will_send_agrees_with_what_the_network_takes(): void
    {
        foreach (SocialMediaKind::cases() as $kind) {
            $this->assertNull((new LinkedInPublisher)->cannotSend($kind), 'LinkedIn '.$kind->value);
            $this->assertSame(
                SocialProvider::YouTube->refuses($kind),
                (new YouTubePublisher)->cannotSend($kind),
                'YouTube '.$kind->value,
            );
        }

        $this->assertNull((new YouTubePublisher)->cannotSend(SocialMediaKind::Video));
    }

    // ── LinkedIn ────────────────────────────────────────────────

    public function test_a_linkedin_text_post_names_the_version_the_member_and_returns_the_header_id(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:777'])]);

        $publisher = new LinkedInPublisher;
        $id = $publisher->publish($this->unsaved(SocialProvider::LinkedIn, 'abc123'), new PostPayload(body: 'Sweep (today) only'));

        $this->assertSame('urn:li:share:777', $id);
        $this->assertSame(
            'https://www.linkedin.com/feed/update/urn:li:share:777/',
            $publisher->permalink($this->unsaved(SocialProvider::LinkedIn), $id),
        );

        Http::assertSent(function (Request $r) {
            $body = $r->data();

            return $r->url() === 'https://api.linkedin.com/rest/posts'
                && $r->method() === 'POST'
                && $r->header('LinkedIn-Version')[0] === LinkedInPublisher::VERSION
                && $r->header('X-Restli-Protocol-Version')[0] === '2.0.0'
                && $r->header('Authorization')[0] === 'Bearer a-token'
                && $body['author'] === 'urn:li:person:abc123'
                && $body['commentary'] === 'Sweep \\(today\\) only'
                && $body['visibility'] === 'PUBLIC'
                && $body['distribution']['feedDistribution'] === 'MAIN_FEED'
                && $body['lifecycleState'] === 'PUBLISHED'
                && ! isset($body['content']);
        });
    }

    public function test_a_remote_id_that_is_already_a_urn_is_the_author_as_it_stands(): void
    {
        $this->assertSame(
            'urn:li:organization:42',
            LinkedInPublisher::author($this->unsaved(SocialProvider::LinkedIn, 'urn:li:organization:42')),
        );
    }

    public function test_reserved_characters_are_escaped_and_hashtags_are_left_to_link(): void
    {
        $this->assertSame(
            'Call \\(555\\) \\* \\@home \\[x\\] \\{y\\} a\\_b \\~ \\| \\< \\> \\\\ \\# 1 #chimney #Sweep2026',
            LinkedInPublisher::littleText('Call (555) * @home [x] {y} a_b ~ | < > \\ # 1 #chimney #Sweep2026'),
        );
    }

    public function test_a_linkedin_post_with_no_words_and_no_media_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessage('empty post');

        try {
            (new LinkedInPublisher)->publish($this->unsaved(SocialProvider::LinkedIn), new PostPayload(body: '   '));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_linkedin_picture_is_registered_uploaded_and_then_referenced(): void
    {
        Http::fake([
            'api.linkedin.com/rest/images*' => Http::response(['value' => [
                'uploadUrl' => 'https://upload.linkedin.test/img/1',
                'image' => 'urn:li:image:IMG1',
            ]]),
            'upload.linkedin.test/*' => Http::response('', 201),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:8']),
        ]);

        $id = (new LinkedInPublisher)->publish(
            $this->unsaved(SocialProvider::LinkedIn),
            new PostPayload(body: 'With a picture', filePath: $this->temp('png', 'PNGBYTES')),
        );

        $this->assertSame('urn:li:share:8', $id);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload'
            && $r->data()['initializeUploadRequest']['owner'] === 'urn:li:person:remote-1'
            && $r->header('LinkedIn-Version')[0] === LinkedInPublisher::VERSION);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.linkedin.test/img/1'
            && $r->method() === 'PUT'
            && $r->body() === 'PNGBYTES');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/posts'
            && $r->data()['content']['media']['id'] === 'urn:li:image:IMG1');
    }

    public function test_a_picture_linkedin_cannot_take_is_refused_in_words_before_anything_is_sent(): void
    {
        Http::fake();

        try {
            (new LinkedInPublisher)->publish(
                $this->unsaved(SocialProvider::LinkedIn),
                new PostPayload(body: 'x', filePath: $this->temp('webp', 'W')),
            );
            $this->fail('A .webp was sent to LinkedIn.');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString('JPG, PNG or GIF', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_linkedin_video_goes_up_in_linkedins_parts_and_is_finalised_with_their_etags_in_order(): void
    {
        $bytes = str_repeat('A', 50_000).str_repeat('B', 40_000);

        Http::fake(function (Request $r) {
            return match (true) {
                str_contains($r->url(), 'action=initializeUpload') => Http::response(['value' => [
                    'video' => 'urn:li:video:V1',
                    'uploadToken' => 'tok-1',
                    'uploadInstructions' => [
                        ['uploadUrl' => 'https://upload.linkedin.test/v/0', 'firstByte' => 0, 'lastByte' => 49_999],
                        ['uploadUrl' => 'https://upload.linkedin.test/v/1', 'firstByte' => 50_000, 'lastByte' => 89_999],
                    ],
                ]]),
                str_starts_with($r->url(), 'https://upload.linkedin.test/v/') => Http::response('', 200, [
                    'ETag' => 'etag-'.substr($r->url(), -1),
                ]),
                str_contains($r->url(), 'action=finalizeUpload') => Http::response('', 200),
                $r->url() === 'https://api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:ugcPost:9']),
                default => Http::response('unexpected '.$r->url(), 500),
            };
        });

        $id = (new LinkedInPublisher)->publish(
            $this->unsaved(SocialProvider::LinkedIn),
            new PostPayload(body: 'A video', filePath: $this->temp('mp4', $bytes), title: 'Chimney day'),
        );

        $this->assertSame('urn:li:ugcPost:9', $id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'videos?action=initializeUpload')
            && $r->data()['initializeUploadRequest']['fileSizeBytes'] === 90_000
            && $r->data()['initializeUploadRequest']['owner'] === 'urn:li:person:remote-1');

        // Each part is exactly LinkedIn's inclusive range.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.linkedin.test/v/0'
            && $r->body() === str_repeat('A', 50_000));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.linkedin.test/v/1'
            && $r->body() === str_repeat('B', 40_000));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'videos?action=finalizeUpload')
            && $r->data()['finalizeUploadRequest'] === [
                'video' => 'urn:li:video:V1',
                'uploadToken' => 'tok-1',
                'uploadedPartIds' => ['etag-0', 'etag-1'],
            ]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/posts'
            && $r->data()['content']['media'] === ['id' => 'urn:li:video:V1', 'title' => 'Chimney day']);
    }

    public function test_a_video_linkedin_cannot_take_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        foreach ([['mov', str_repeat('V', 90_000), 'MP4 only'], ['mp4', 'tiny', 'between 75 KB and 500 MB']] as [$ext, $bytes, $says]) {
            try {
                (new LinkedInPublisher)->publish(
                    $this->unsaved(SocialProvider::LinkedIn),
                    new PostPayload(filePath: $this->temp($ext, $bytes)),
                );
                $this->fail('A .'.$ext.' was sent to LinkedIn.');
            } catch (SocialPublishFailed $e) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_linkedins_own_words_reach_the_person_when_it_refuses_a_post(): void
    {
        Http::fake(['api.linkedin.com/rest/posts' => Http::response([
            'message' => 'commentary length exceeds the allowed maximum',
            'status' => 400,
        ], 400)]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessage('LinkedIn refused it: commentary length exceeds the allowed maximum');

        (new LinkedInPublisher)->publish($this->unsaved(SocialProvider::LinkedIn), new PostPayload(body: 'Hi'));
    }

    public function test_a_401_from_linkedin_marks_the_connection_for_reconnecting(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, 'linkedin', ['access_token' => 'dead', 'expires_at' => now()->addDays(20)->toIso8601String()]);
        Http::fake(['api.linkedin.com/rest/posts' => Http::response(['message' => 'Invalid access token', 'status' => 401], 401)]);

        try {
            $this->inTenant($t['org'], fn () => (new LinkedInPublisher)->publish($row, new PostPayload(body: 'Hi')));
            $this->fail('A 401 was treated as a post.');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString('Reconnect', $e->getMessage());
        }

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    public function test_a_refusal_that_is_about_the_post_does_not_mark_the_connection(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, 'linkedin', ['access_token' => 'fine']);
        Http::fake(['api.linkedin.com/rest/posts' => Http::response(['message' => 'Not enough permissions', 'status' => 403], 403)]);

        try {
            $this->inTenant($t['org'], fn () => (new LinkedInPublisher)->publish($row, new PostPayload(body: 'Hi')));
        } catch (SocialPublishFailed) {
        }

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $saved->status);
    }

    public function test_an_expired_linkedin_token_with_nothing_to_renew_it_asks_for_a_reconnect_and_sends_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, 'linkedin', ['access_token' => 'old', 'expires_at' => now()->subDay()->toIso8601String()]);
        Http::fake();

        try {
            $this->inTenant($t['org'], fn () => (new LinkedInPublisher)->publish($row, new PostPayload(body: 'Hi')));
            $this->fail('An expired token was used.');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString('60 days', $e->getMessage());
            $this->assertStringContainsString('Reconnect', $e->getMessage());
        }

        Http::assertNothingSent();

        // Marked broken (2026-11-12): nothing can renew it, so the card must
        // say so rather than stay green until a post fails.
        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    public function test_a_linkedin_token_inside_its_last_minutes_is_still_used_when_it_cannot_be_renewed(): void
    {
        $row = $this->unsaved(SocialProvider::LinkedIn);
        $row->connect(['access_token' => 'nearly', 'expires_at' => now()->addMinutes(3)->toIso8601String()]);

        $this->assertSame('nearly', (new LinkedInPublisher)->token($row));
    }

    public function test_an_expired_linkedin_token_with_a_refresh_token_is_renewed_stored_and_used(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, 'linkedin', [
            'access_token' => 'old',
            'refresh_token' => 'li-refresh',
            'expires_at' => now()->subDay()->toIso8601String(),
        ]);

        Http::fake([
            'www.linkedin.com/oauth/v2/accessToken' => Http::response(['access_token' => 'new', 'expires_in' => 5_184_000]),
            'api.linkedin.com/rest/posts' => Http::response('', 201, ['x-restli-id' => 'urn:li:share:1']),
        ]);

        $this->inTenant($t['org'], fn () => (new LinkedInPublisher)->publish($row, new PostPayload(body: 'Hi')));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
            && $r->data()['grant_type'] === 'refresh_token'
            && $r->data()['refresh_token'] === 'li-refresh'
            && $r->data()['client_id'] === 'li-client');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/rest/posts'
            && $r->header('Authorization')[0] === 'Bearer new');

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame('new', $saved->credentials['access_token']);
        $this->assertSame('li-refresh', $saved->credentials['refresh_token']);
        $this->assertTrue(now()->addDays(59)->lt($saved->credentials['expires_at']));
    }

    public function test_a_refused_linkedin_renewal_marks_the_connection_for_reconnecting(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, 'linkedin', [
            'access_token' => 'old',
            'refresh_token' => 'revoked',
            'expires_at' => now()->subDay()->toIso8601String(),
        ]);
        Http::fake(['www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            $this->inTenant($t['org'], fn () => (new LinkedInPublisher)->token($row));
            $this->fail('A refused renewal returned a token.');
        } catch (SocialPublishFailed) {
        }

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    // ── YouTube ─────────────────────────────────────────────────

    public function test_a_youtube_video_is_a_resumable_upload_in_two_steps_signed_by_the_youtube_client(): void
    {
        $this->fakeYouTube();

        $publisher = new YouTubePublisher;
        $id = $publisher->publish(
            $this->unsaved(SocialProvider::YouTube),
            new PostPayload(body: "Chimney day <3\nSecond line", filePath: $this->temp('mp4', 'VIDEOBYTES')),
        );

        $this->assertSame('yt-video-1', $id);
        $this->assertSame('https://www.youtube.com/watch?v=yt-video-1', $publisher->permalink($this->unsaved(SocialProvider::YouTube), $id));

        // Refreshed with the YouTube OAuth client, not Ads or Business Profile.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r->data()['client_id'] === 'yt-client.apps.googleusercontent.com'
            && $r->data()['refresh_token'] === 'r');

        Http::assertSent(function (Request $r) {
            if (! str_starts_with($r->url(), 'https://www.googleapis.com/upload/youtube/v3/videos?')) {
                return false;
            }

            $body = $r->data();

            return $r->method() === 'POST'
                && str_contains($r->url(), 'uploadType=resumable')
                && str_contains($r->url(), 'part=snippet,status')
                && $r->header('Authorization')[0] === 'Bearer fresh-google'
                && $r->header('X-Upload-Content-Length')[0] === '10'
                && $r->header('X-Upload-Content-Type')[0] === 'video/mp4'
                // First line of the body, with the < and > YouTube refuses removed.
                && $body['snippet']['title'] === 'Chimney day 3'
                && $body['snippet']['description'] === "Chimney day 3\nSecond line"
                && $body['snippet']['categoryId'] === '22'
                && $body['status']['privacyStatus'] === 'public'
                && $body['status']['selfDeclaredMadeForKids'] === false;
        });

        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.youtube.test/session/1'
            && $r->method() === 'PUT'
            && $r->body() === 'VIDEOBYTES'
            && $r->header('Authorization')[0] === 'Bearer fresh-google');
    }

    public function test_the_youtube_title_and_privacy_come_from_the_post_when_given(): void
    {
        $this->fakeYouTube();

        foreach ([['unlisted', 'unlisted'], ['everyone', 'public']] as [$asked, $sent]) {
            (new YouTubePublisher)->publish(
                $this->unsaved(SocialProvider::YouTube),
                new PostPayload(body: 'Words', filePath: $this->temp('mp4', 'V'), title: 'The title', options: ['privacy_status' => $asked]),
            );

            Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable')
                && $r->data()['snippet']['title'] === 'The title'
                && $r->data()['status']['privacyStatus'] === $sent);
        }
    }

    public function test_a_youtube_video_with_no_words_gets_a_title_anyway(): void
    {
        $this->fakeYouTube();

        (new YouTubePublisher)->publish(
            $this->unsaved(SocialProvider::YouTube),
            new PostPayload(filePath: $this->temp('mp4', 'V')),
        );

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'uploadType=resumable')
            && $r->data()['snippet']['title'] === 'EzBizi video');
    }

    public function test_youtube_refuses_a_picture_in_the_networks_own_words_and_sends_nothing(): void
    {
        Http::fake();

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessage((string) SocialProvider::YouTube->refuses(SocialMediaKind::Image));

        try {
            (new YouTubePublisher)->publish(
                $this->unsaved(SocialProvider::YouTube),
                new PostPayload(filePath: $this->temp('png', 'P')),
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_google_account_with_no_channel_is_told_so(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-google']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response(['error' => [
                'code' => 401,
                'message' => 'Unauthorized',
                'errors' => [['reason' => 'youtubeSignupRequired']],
            ]], 401),
        ]);

        $this->expectException(SocialPublishFailed::class);
        $this->expectExceptionMessage('no YouTube channel yet');

        (new YouTubePublisher)->publish($this->unsaved(SocialProvider::YouTube), new PostPayload(filePath: $this->temp('mp4', 'V')));
    }

    public function test_a_session_without_an_upload_address_is_a_sentence_not_a_put_to_nowhere(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-google']),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200),
        ]);

        try {
            (new YouTubePublisher)->publish($this->unsaved(SocialProvider::YouTube), new PostPayload(filePath: $this->temp('mp4', 'V')));
            $this->fail('No Location header was treated as an upload.');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString('did not hand back an address', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function fakeYouTube(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'fresh-google', 'expires_in' => 3599]),
            'www.googleapis.com/upload/youtube/v3/videos*' => Http::response('', 200, ['Location' => 'https://upload.youtube.test/session/1']),
            'upload.youtube.test/*' => Http::response(['id' => 'yt-video-1', 'status' => ['uploadStatus' => 'uploaded']]),
        ]);
    }

    private function unsaved(SocialProvider $provider, string $remoteId = 'remote-1'): SocialConnection
    {
        $connection = new SocialConnection;
        $connection->forceFill([
            'provider' => $provider->value,
            'remote_id' => $remoteId,
            'display_name' => 'Test account',
        ]);
        $connection->connect(['refresh_token' => 'r', 'access_token' => 'a-token']);

        // LinkedIn reads a refresh token as "can be renewed"; most LinkedIn
        // connections have none, so the default LinkedIn row has none either.
        if ($provider === SocialProvider::LinkedIn) {
            $connection->connect(['access_token' => 'a-token']);
        }

        return $connection;
    }

    /** @param  array<string, mixed>  $credentials */
    private function saved(array $t, string $provider, array $credentials): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $credentials) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'provider' => $provider,
                'remote_id' => 'member-1',
                'display_name' => 'A-1 Chimney',
            ]);
            $row->connect($credentials);
            $row->save();

            return $row;
        });
    }

    /** Delete from the network (2026-10-15): the URN encoded in the path. */
    public function test_linkedin_deletes_the_post_it_published(): void
    {
        Http::fake();

        (new LinkedInPublisher)->deleteRemote($this->unsaved(SocialProvider::LinkedIn), 'urn:li:share:123');

        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/rest/posts/urn%3Ali%3Ashare%3A123'));
    }

    private function temp(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'social').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }
}
