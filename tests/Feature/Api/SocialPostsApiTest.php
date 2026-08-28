<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Compose and publish.
 *
 * The queue is `sync` under test, so a request that queues a send has really
 * sent it by the time the response comes back — which is what lets these assert
 * on the target's outcome rather than on the fact that a job was dispatched.
 * The network itself is faked; what is pinned here is the part EzBizi owns:
 * what it refuses before sending, and what it records after.
 */
class SocialPostsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/posts';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
    }

    public function test_publishing_is_refused_to_a_role_that_can_only_look(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $row    = $this->connection($t, 'company', $t['company']->id);
        $reader = $this->userWithRole($t, 'Reader', ['marketing.view']);

        $this->signedInAs($reader)
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Hello'])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'publish to a social account')
                    && ! str_contains($m, 'marketing.edit'),
            );

        $this->assertNothingWasWritten($t);
    }

    public function test_a_sibling_company_cannot_publish_as_this_companys_account(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $row = $this->connection($t, 'company', $t['company']->id);

        $this->lookingAt($t['admin'], 'company', $premier->id);

        $this->signedInAs($t['admin']->fresh())
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body'        => 'Posting as somebody else',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('connections');

        $this->assertNothingWasWritten($t);
    }

    public function test_a_network_that_needs_a_picture_refuses_words_alone_before_anything_is_written(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Just words'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.connections.0',
                fn (string $m) => str_contains($m, 'Attach'),
            );

        // The point of refusing early: nothing queued, and nothing sent.
        $this->assertNothingWasWritten($t);
        Http::assertNothingSent();
    }

    public function test_a_connected_network_with_no_publisher_says_so_and_says_the_account_is_fine(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, provider: 'facebook_page');

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Hello'])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.connections.0',
                fn (string $m) => str_contains($m, 'not built yet')
                    && str_contains($m, 'without reconnecting'),
            );

        $this->assertNothingWasWritten($t);
    }

    /**
     * The gap a real post fell through on 2026-08-28.
     *
     * Pinterest ACCEPTS video — `SocialProvider::refuses()` rightly said yes —
     * and EzBizi's poster does not SEND it. Two different facts, and only the
     * network-level one was checked, so the row was written, queued, run, and
     * failed. Everything this controller promises to refuse before writing a
     * row, it now actually refuses.
     */
    public function test_a_network_that_takes_video_but_has_no_video_poster_is_refused_before_a_row_is_written(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body'        => 'A sweep, on video.',
                'media'       => UploadedFile::fake()->create('sweep.mp4', 12, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.connections.0',
                // The sentence separates "your account is broken" from "we have
                // not built this", and names the half that IS working so nobody
                // goes hunting through their Pinterest settings.
                fn (string $m) => str_contains($m, 'cannot post video to Pinterest yet')
                    && str_contains($m, 'connection is fine'),
            );

        $this->assertNothingWasWritten($t);
        Http::assertNothingSent();
    }

    public function test_a_post_with_neither_words_nor_a_file_is_refused(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_an_image_reaches_pinterest_and_the_target_carries_the_pin_id(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins'    => Http::response(['id' => 'pin-9']),
        ]);

        $response = $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body'        => 'New flue liner, same day.',
                'media'       => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            ])
            ->assertCreated();

        $response->assertJsonPath('data.targets.0.status', SocialPostTarget::STATUS_SENT);
        $response->assertJsonPath('data.targets.0.account', 'A-1 Chimney');

        $this->inTenant($t['org'], function () {
            $target = SocialPostTarget::query()->sole();

            $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status);
            $this->assertSame('pin-9', $target->remote_id);
            $this->assertNull($target->failure);
            $this->assertNotNull($target->sent_at);

            // The file is kept with the post, not thrown away after the send:
            // the record of what went out includes the picture that went out.
            $post = SocialPost::query()->sole();
            $this->assertNotNull($post->media_path);
            Storage::disk($post->media_disk)->assertExists($post->media_path);
        });

        // The picture really went in the body, base64, rather than as a link to
        // an EzBizi URL — the privacy property PinterestPublisher's docblock
        // claims. Asserted here because this is the path a tenant actually uses.
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pinterest.com/v5/pins'
            && ($request['media_source']['source_type'] ?? null) === 'image_base64');
    }

    public function test_a_send_raises_the_automation_event_and_a_refusal_raises_the_other(): void
    {
        $t   = $this->provisionTenant('A1-Group');
        $ok  = $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $bad = $this->connection($t, 'company', $t['company']->id, 'A-1 Roofing', 'pin-b');

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins'    => Http::sequence()
                ->push(['id' => 'pin-9'])
                ->push(['message' => 'That board is archived.'], 400),
        ]);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$ok->getKey(), $bad->getKey()],
                'body'        => 'Two accounts, one of them unhappy.',
                'media'       => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            ])
            ->assertCreated();

        $this->inTenant($t['org'], function () {
            $targets = SocialPostTarget::query()->get()->keyBy('status');

            // The whole point of one job per target: the second account failing
            // did not cost the first one its post.
            $this->assertCount(2, $targets);
            $this->assertSame('pin-9', $targets[SocialPostTarget::STATUS_SENT]->remote_id);
            $this->assertStringContainsString(
                'That board is archived.',
                (string) $targets[SocialPostTarget::STATUS_FAILED]->failure,
            );

            $raised = AutomationEvent::query()->pluck('event_key')->all();

            sort($raised);
            $this->assertSame(['social.publish_failed', 'social.published'], $raised);
        });
    }

    public function test_the_list_shows_what_this_view_sent_and_not_a_siblings(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name'            => 'Premier Garage Repair',
        ]));

        $mine     = $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $theirs   = $this->connection($t, 'company', $premier->id, 'Premier', 'pin-b');

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins'    => Http::response(['id' => 'pin-9']),
        ]);

        foreach ([$mine, $theirs] as $connection) {
            $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
                'connections' => [$connection->getKey()],
                'body'        => 'From '.$connection->display_name,
                'media'       => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            ])->assertCreated();
        }

        $this->lookingAt($t['admin'], 'company', $t['company']->id);

        $bodies = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.body');

        $this->assertSame(['From A-1 Chimney'], $bodies);
    }

    public function test_the_account_list_says_which_accounts_can_be_posted_to(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $this->connection($t, 'company', $t['company']->id, 'A-1 on Facebook', 'fb-a', 'facebook_page');

        $rows = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections')
            ->assertOk()
            ->json('data'))
            ->keyBy('provider');

        $this->assertTrue($rows['pinterest']['can_publish']);
        $this->assertNull($rows['pinterest']['cannot_publish_because']);

        $this->assertFalse($rows['facebook_page']['can_publish']);
        $this->assertStringContainsString(
            'not built yet',
            (string) $rows['facebook_page']['cannot_publish_because'],
        );
    }

    private function assertNothingWasWritten(array $t): void
    {
        $this->inTenant($t['org'], function () {
            $this->assertSame(0, SocialPost::query()->count());
            $this->assertSame(0, SocialPostTarget::query()->count());
        });
    }

    private function connection(
        array $t,
        string $scopeType,
        string $scopeId,
        string $name = 'A-1 Chimney',
        string $remoteId = 'pin-1',
        string $provider = 'pinterest',
    ): SocialConnection {
        return $this->inTenant($t['org'], function () use ($t, $scopeType, $scopeId, $name, $remoteId, $provider) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type'      => $scopeType,
                'scope_id'        => $scopeId,
                'provider'        => $provider,
                'remote_id'       => $remoteId,
                'display_name'    => $name,
                'handle'          => strtolower(str_replace(' ', '', $name)),
            ]);
            $row->connect([
                'refresh_token' => 'pinr-a-refresh-token',
                'access_token'  => 'pina-an-access-token',
            ]);
            $row->save();

            return $row;
        });
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }
}
