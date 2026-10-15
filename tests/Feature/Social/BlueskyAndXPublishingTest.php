<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\BlueskyPublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\Publishing\XPublisher;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Bluesky and X: the shape of what each is sent, and the two rotating-token
 * renewals, which are the part a live send only explains once.
 */
class BlueskyAndXPublishingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const DID = 'did:plc:abc123';

    public function test_both_networks_now_have_a_publisher_and_the_composer_is_told(): void
    {
        foreach ([SocialProvider::Bluesky, SocialProvider::X] as $provider) {
            $this->assertNotNull(Publishers::for($provider), $provider->value);
            $this->assertNull(Publishers::refusalFor($provider), $provider->value);
        }

        // Bluesky's video refusal is the network's sentence; X's is ours.
        $this->assertSame('Bluesky does not take video.', (new BlueskyPublisher)->cannotSend(SocialMediaKind::Video));
        $this->assertStringContainsString('the poster is not built', (string) (new XPublisher)->cannotSend(SocialMediaKind::Video));
        $this->assertNull((new XPublisher)->cannotSend(SocialMediaKind::Image));
        $this->assertNull((new BlueskyPublisher)->cannotSend(SocialMediaKind::Image));
    }

    // ── Bluesky ──────────────────────────────────────────────────

    public function test_bluesky_writes_a_post_record_with_a_link_facet_at_byte_offsets(): void
    {
        Http::fake(['bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://'.self::DID.'/app.bsky.feed.post/3kabc', 'cid' => 'bafy',
        ])]);

        // "é" is two bytes: a character offset would put the link one byte early.
        $text = 'Café open — see https://ezbizi.com.';
        $uri = (new BlueskyPublisher)->publish($this->unsaved(SocialProvider::Bluesky, self::DID, $this->jwt(3600)), new PostPayload(body: $text));

        $this->assertSame('at://'.self::DID.'/app.bsky.feed.post/3kabc', $uri);

        Http::assertSent(function ($r) use ($text) {
            $d = $r->data();
            $start = strpos($text, 'https://');

            return $r->header('Authorization')[0] === 'Bearer '.$this->jwt(3600)
                && $d['repo'] === self::DID
                && $d['collection'] === 'app.bsky.feed.post'
                && $d['record']['$type'] === 'app.bsky.feed.post'
                && $d['record']['text'] === $text
                && $d['record']['facets'][0]['index'] === ['byteStart' => $start, 'byteEnd' => $start + strlen('https://ezbizi.com')]
                && $d['record']['facets'][0]['features'][0]['uri'] === 'https://ezbizi.com'
                && ! isset($d['record']['embed']);
        });

        $this->assertSame(
            'https://bsky.app/profile/'.self::DID.'/post/3kabc',
            (new BlueskyPublisher)->permalink($this->unsaved(SocialProvider::Bluesky, self::DID), $uri),
        );
    }

    public function test_bluesky_uploads_the_picture_as_a_blob_and_embeds_it(): void
    {
        $blob = ['$type' => 'blob', 'ref' => ['$link' => 'bafkrei'], 'mimeType' => 'image/png', 'size' => 8];

        Http::fake([
            'bsky.social/xrpc/com.atproto.repo.uploadBlob' => Http::response(['blob' => $blob]),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.self::DID.'/app.bsky.feed.post/3k']),
        ]);

        (new BlueskyPublisher)->publish(
            $this->unsaved(SocialProvider::Bluesky, self::DID, $this->jwt(3600)),
            new PostPayload(body: 'A picture', filePath: $this->temp('png', 'PNGBYTES')),
        );

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'uploadBlob')
            && $r->body() === 'PNGBYTES'
            && $r->header('Content-Type')[0] === 'image/png');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'createRecord')
            && $r->data()['record']['embed']['$type'] === 'app.bsky.embed.images'
            && $r->data()['record']['embed']['images'][0]['image'] === $blob
            && $r->data()['record']['embed']['images'][0]['alt'] === '');
    }

    public function test_bluesky_refuses_a_picture_over_a_megabyte_and_text_over_300_before_sending(): void
    {
        // One fake for the whole test: a second Http::fake() stacks behind the
        // first rather than replacing it.
        Http::fake(['*' => Http::response(['uri' => 'at://'.self::DID.'/app.bsky.feed.post/3k'])]);
        $row = $this->unsaved(SocialProvider::Bluesky, self::DID, $this->jwt(3600));

        $this->assertRefused(
            fn () => (new BlueskyPublisher)->publish($row, new PostPayload(filePath: $this->temp('jpg', str_repeat('x', 1_000_001)))),
            'up to 1 MB',
        );
        $this->assertRefused(
            fn () => (new BlueskyPublisher)->publish($row, new PostPayload(body: str_repeat('a', 301))),
            '300 characters',
        );

        Http::assertNothingSent();

        // 300 accented letters made of two code points each are 300, not 600.
        (new BlueskyPublisher)->publish($row, new PostPayload(body: str_repeat("e\u{0301}", 300)));
        Http::assertSentCount(1);
    }

    public function test_bluesky_renews_an_expired_session_and_stores_the_rotated_refresh_jwt(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, SocialProvider::Bluesky, self::DID, ['access_token' => $this->jwt(-60), 'refresh_token' => 'refresh.old']);
        $fresh = $this->jwt(7200);

        Http::fake([
            'bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
                'did' => self::DID, 'handle' => 'ezbizi.bsky.social', 'accessJwt' => $fresh, 'refreshJwt' => 'refresh.new',
            ]),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response(['uri' => 'at://'.self::DID.'/app.bsky.feed.post/3k']),
        ]);

        $this->inTenant($t['org'], fn () => (new BlueskyPublisher)->publish($row, new PostPayload(body: 'Hello')));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'refreshSession')
            && $r->header('Authorization')[0] === 'Bearer refresh.old');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'createRecord')
            && $r->header('Authorization')[0] === 'Bearer '.$fresh);

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame($fresh, $saved->credentials['access_token']);
        $this->assertSame('refresh.new', $saved->credentials['refresh_token']);
    }

    public function test_a_refused_bluesky_renewal_marks_the_connection_for_reconnecting(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, SocialProvider::Bluesky, self::DID, ['access_token' => $this->jwt(-60), 'refresh_token' => 'refresh.old']);

        Http::fake(['bsky.social/*' => Http::response(['error' => 'ExpiredToken', 'message' => 'Token has expired'], 400)]);

        $this->assertRefused(
            fn () => $this->inTenant($t['org'], fn () => (new BlueskyPublisher)->publish($row, new PostPayload(body: 'Hello'))),
            'app password',
        );

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'createRecord'));
        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    // ── X ────────────────────────────────────────────────────────

    public function test_x_posts_words_and_links_to_the_post(): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::response(['data' => ['id' => '1846', 'text' => 'Hello']], 201)]);

        $id = (new XPublisher)->publish($this->unsaved(SocialProvider::X, 'x-1', 'live', now()->addHour()), new PostPayload(body: 'Hello'));

        $this->assertSame('1846', $id);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.x.com/2/tweets'
            && $r->header('Authorization')[0] === 'Bearer live'
            && $r->data() === ['text' => 'Hello']);
        $this->assertSame('https://x.com/i/web/status/1846', (new XPublisher)->permalink($this->unsaved(SocialProvider::X, 'x-1'), $id));
    }

    public function test_x_uploads_the_picture_then_names_it_in_the_post(): void
    {
        Http::fake([
            'api.x.com/2/media/upload' => Http::response(['data' => ['id' => '7001', 'media_key' => '3_7001']]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1847']], 201),
        ]);

        (new XPublisher)->publish(
            $this->unsaved(SocialProvider::X, 'x-1', 'live', now()->addHour()),
            new PostPayload(body: 'With a picture', filePath: $this->temp('jpg', 'JPEGBYTES')),
        );

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/media/upload') || ! $r->isMultipart()) {
                return false;
            }

            $parts = collect($r->data())->keyBy('name');

            return $parts['media']['contents'] === 'JPEGBYTES'
                && $parts['media_category']['contents'] === 'tweet_image';
        });

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tweets')
            && $r->data() === ['text' => 'With a picture', 'media' => ['media_ids' => ['7001']]]);
    }

    public function test_x_refuses_over_280_and_a_2xx_carrying_only_errors(): void
    {
        Http::fake(['api.x.com/2/tweets' => Http::response(['errors' => [['message' => 'You are not allowed to create a Tweet with duplicate content.']]])]);
        $row = $this->unsaved(SocialProvider::X, 'x-1', 'live', now()->addHour());

        $this->assertRefused(fn () => (new XPublisher)->publish($row, new PostPayload(body: str_repeat('a', 281))), '280 characters');
        Http::assertNothingSent();

        $this->assertRefused(fn () => (new XPublisher)->publish($row, new PostPayload(body: 'Again')), 'duplicate content');
    }

    public function test_x_renews_an_expired_token_and_stores_the_rotated_refresh_token(): void
    {
        config(['services.x.client_id' => 'cid', 'services.x.client_secret' => 'secret']);
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, SocialProvider::X, 'x-1', [
            'access_token' => 'old', 'refresh_token' => 'refresh.old', 'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response([
                'token_type' => 'bearer', 'access_token' => 'new', 'refresh_token' => 'refresh.new', 'expires_in' => 7200,
            ]),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '1848']], 201),
        ]);

        $this->inTenant($t['org'], fn () => (new XPublisher)->publish($row, new PostPayload(body: 'Hello')));

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth2/token')
            && $r->data() === ['grant_type' => 'refresh_token', 'refresh_token' => 'refresh.old']
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('cid:secret'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tweets') && $r->header('Authorization')[0] === 'Bearer new');

        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame('new', $saved->credentials['access_token']);
        $this->assertSame('refresh.new', $saved->credentials['refresh_token']);
        $this->assertTrue(now()->addMinutes(110)->lt($saved->credentials['expires_at']));
    }

    public function test_an_x_token_of_unknown_age_is_renewed_and_a_live_one_is_not(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $unknown = $this->saved($t, SocialProvider::X, 'x-1', ['access_token' => 'old', 'refresh_token' => 'refresh.old']);

        Http::fake(['api.x.com/2/oauth2/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'refresh.new', 'expires_in' => 7200])]);

        $this->assertSame('new', $this->inTenant($t['org'], fn () => (new XPublisher)->token($unknown)));

        $live = $this->unsaved(SocialProvider::X, 'x-2', 'live', now()->addHour());
        $this->assertSame('live', (new XPublisher)->token($live));
        Http::assertSentCount(1); // only the renewal above
    }

    public function test_a_refused_x_renewal_marks_the_connection_for_reconnecting(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->saved($t, SocialProvider::X, 'x-1', [
            'access_token' => 'old', 'refresh_token' => 'refresh.spent', 'expires_at' => now()->subMinute()->toIso8601String(),
        ]);

        Http::fake(['api.x.com/2/oauth2/token' => Http::response([
            'error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.',
        ], 400)]);

        $this->assertRefused(
            fn () => $this->inTenant($t['org'], fn () => (new XPublisher)->publish($row, new PostPayload(body: 'Hello'))),
            'connecting again',
        );

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/tweets'));
        $saved = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->getKey()));
        $this->assertSame(SocialConnection::STATUS_BROKEN, $saved->status);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function assertRefused(callable $send, string $sentence): void
    {
        try {
            $send();
            $this->fail('It was sent; it should have been refused with "'.$sentence.'".');
        } catch (SocialPublishFailed $e) {
            $this->assertStringContainsString($sentence, $e->getMessage());
        }
    }

    /** A JWT whose exp is $seconds from now. Unsigned: only the exp is read. */
    private function jwt(int $seconds): string
    {
        $part = fn (array $a) => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');

        // Fixed to the minute so two calls in one test agree.
        return $part(['alg' => 'HS256']).'.'.$part(['exp' => now()->startOfMinute()->addSeconds($seconds)->getTimestamp() + 60]).'.sig';
    }

    private function unsaved(SocialProvider $provider, string $remoteId, ?string $access = 'a-token', $expiresAt = null): SocialConnection
    {
        $row = new SocialConnection;
        $row->forceFill(['provider' => $provider->value, 'remote_id' => $remoteId, 'display_name' => 'Test account']);
        $row->connect(array_filter([
            'refresh_token' => 'r',
            'access_token' => $access,
            'expires_at' => $expiresAt?->toIso8601String(),
        ]));

        return $row;
    }

    /** @param  array<string, string>  $credentials */
    private function saved(array $t, SocialProvider $provider, string $remoteId, array $credentials): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remoteId, $credentials) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'company',
                'scope_id' => $t['company']->id,
                'provider' => $provider->value,
                'remote_id' => $remoteId,
                'display_name' => 'EzBizi US',
            ]);
            $row->connect($credentials);
            $row->save();

            return $row;
        });
    }

    /** Delete from the network (2026-10-15): the request each API expects. */
    public function test_x_and_bluesky_delete_the_post_they_published(): void
    {
        Http::fake();

        (new XPublisher)->deleteRemote($this->unsaved(SocialProvider::X, 'x-1', 'live', now()->addHour()), '1846');
        (new BlueskyPublisher)->deleteRemote(
            $this->unsaved(SocialProvider::Bluesky, self::DID, $this->jwt(3600)),
            'at://'.self::DID.'/app.bsky.feed.post/3kabc',
        );

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/2/tweets/1846'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/xrpc/com.atproto.repo.deleteRecord')
            && $r->data() === ['repo' => self::DID, 'collection' => 'app.bsky.feed.post', 'rkey' => '3kabc']);
    }

    private function temp(string $extension, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'social').'.'.$extension;
        file_put_contents($path, $contents);

        return $path;
    }
}
