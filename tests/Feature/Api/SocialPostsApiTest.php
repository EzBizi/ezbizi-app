<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
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
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);
        // Holds the Compose TAB so the refusal under test is the publish one.
        // Without it the tab gate answers first with a different sentence,
        // and a test asserting only a 403 would stay green for the wrong reason.
        $reader = $this->userWithRole($t, 'Reader', ['marketing.view', 'marketing.compose.view']);

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
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
        ]));

        $row = $this->connection($t, 'company', $t['company']->id);

        $this->lookingAt($t['admin'], 'company', $premier->id);

        $this->signedInAs($t['admin']->fresh())
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body' => 'Posting as somebody else',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('connections');

        $this->assertNothingWasWritten($t);
    }

    public function test_a_branch_only_user_who_never_used_the_switcher_cannot_publish_as_a_sibling_branch(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $sibling = $this->extraFranchise($t, 'Illinois');
        // Facebook takes words alone, so the only refusal left is the view.
        $row = $this->connection($t, 'franchise', $sibling->id, 'Illinois', 'il-page', 'facebook_page');
        Http::fake();

        $this->signedInAs($this->userWithRole($t, 'Branch', ['marketing.view', 'marketing.compose.view', 'marketing.edit'], onlyAt: $t['franchise']))
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Posting as a sibling'])
            ->assertStatus(422)
            ->assertJsonPath('errors.connections.0', fn (string $m) => str_contains($m, 'not connected in this view'));

        $this->assertNothingWasWritten($t);
        Http::assertNothingSent();
    }

    public function test_a_network_that_needs_a_picture_refuses_words_alone_before_anything_is_written(): void
    {
        $t = $this->provisionTenant('A1-Group');
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
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body' => 'A sweep, on video.',
                'media' => UploadedFile::fake()->create('sweep.mp4', 12, 'video/mp4'),
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

    /**
     * Scheduling — the half of Task 5 that waited, and the promise it makes.
     *
     * A scheduled post sends NOTHING now. Its targets sit in `scheduled`, which
     * is deliberately not `queued`: "waiting to send" for a post due next
     * Tuesday would be the same lie the composer already told once, just
     * slower.
     */
    public function test_a_scheduled_post_sends_nothing_until_its_time(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body' => 'Book your autumn sweep.',
                'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
                'scheduled_for' => now()->addDays(3)->format('Y-m-d H:i'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.targets.0.status', SocialPostTarget::STATUS_SCHEDULED);

        Http::assertNothingSent();

        $this->inTenant($t['org'], function () {
            $post = SocialPost::query()->sole();

            $this->assertNotNull($post->scheduled_for);
            // The clock is the server's answer about the view, never the
            // client's — the composer sends a wall time and nothing else.
            $this->assertNotNull($post->scheduled_timezone);
            $this->assertFalse($post->isDue());
        });
    }

    public function test_the_sweep_releases_a_post_once_it_is_due_and_not_before(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-9']),
        ]);

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$row->getKey()],
            'body' => 'Autumn sweep.',
            'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            'scheduled_for' => now()->addHour()->format('Y-m-d H:i'),
        ])->assertCreated();

        // Not yet. The sweep must be a no-op before the time, or the schedule
        // means nothing.
        $this->artisan('ezbizi:publish-due-social-posts')->assertSuccessful();
        Http::assertNothingSent();

        $this->inTenant($t['org'], fn () => $this->assertSame(
            SocialPostTarget::STATUS_SCHEDULED,
            SocialPostTarget::query()->sole()->status,
        ));

        // Now it is. A whole day, not "two hours": the wall time is read in the
        // VIEW's clock (Etc/GMT+5 by default), so a string built from UTC `now`
        // is not an hour away in absolute terms — it is an hour away on a
        // different clock. That skew is the feature, and a test that assumed
        // otherwise would be asserting the bug.
        $this->travelTo(now()->addDay());
        $this->artisan('ezbizi:publish-due-social-posts')->assertSuccessful();

        $this->inTenant($t['org'], function () {
            $target = SocialPostTarget::query()->sole();

            $this->assertSame(SocialPostTarget::STATUS_SENT, $target->status);
            $this->assertSame('pin-9', $target->remote_id);
        });
    }

    /**
     * A second sweep does not re-send what the first already released.
     *
     * Honest about what this covers: the sweep's own query narrows to
     * `scheduled`, and the first pass moves the row out of it, so THIS test
     * would pass even with the claim removed. It is still worth having — it is
     * the property a person cares about — but the claim below it is what makes
     * the same thing true when two sweeps overlap rather than run in sequence,
     * and that needs its own test.
     */
    public function test_a_second_sweep_does_not_send_the_same_post_again(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-9']),
        ]);

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$row->getKey()],
            'body' => 'Once, please.',
            'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            'scheduled_for' => now()->addMinutes(5)->format('Y-m-d H:i'),
        ])->assertCreated();

        $this->travelTo(now()->addDay());

        $this->artisan('ezbizi:publish-due-social-posts')->assertSuccessful();
        $this->artisan('ezbizi:publish-due-social-posts')->assertSuccessful();

        // One Pin created, not two.
        Http::assertSentCount(2); // boards + pins, from the single send
        $this->inTenant($t['org'], fn () => $this->assertSame(
            1,
            SocialPostTarget::query()->where('status', SocialPostTarget::STATUS_SENT)->count(),
        ));
    }

    /**
     * The wall time belongs to the VIEW's clock, not to the server's.
     *
     * TODO #20. Somebody in the Connecticut branch typing 9am means
     * Connecticut's 9am. Storing the instant instead would be right today and
     * wrong the moment a branch's timezone is corrected — and the promise the
     * person made was about a clock on a wall, not a moment in physics.
     */
    public function test_the_time_is_stored_as_the_view_reads_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$row->getKey()],
            'body' => 'Nine in the morning, wherever I am standing.',
            'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            'scheduled_for' => '2099-03-04 09:00',
        ])->assertCreated();

        $this->inTenant($t['org'], function () {
            $post = SocialPost::query()->sole();

            // Kept as typed — 9am is still 9am in the row.
            $this->assertStringContainsString('2099-03-04 09:00', (string) $post->scheduled_for);

            // And it resolves through the view's clock rather than the
            // server's, so the two only agree when the clocks do.
            $this->assertSame(
                $post->scheduled_timezone,
                $post->dueAt()?->timezone->getName(),
            );
        });
    }

    public function test_a_time_that_has_already_passed_is_refused_rather_than_sent_now(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body' => 'Yesterday.',
                'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
                'scheduled_for' => now()->subDays(2)->format('Y-m-d H:i'),
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.scheduled_for.0',
                fn (string $m) => str_contains($m, 'already passed'),
            );

        // Publishing it immediately would be the least recoverable reading of
        // somebody mistyping the day. A post cannot be unsent.
        $this->assertNothingWasWritten($t);
        Http::assertNothingSent();
    }

    public function test_a_post_with_neither_words_nor_a_file_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => '   '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');
    }

    public function test_an_image_reaches_pinterest_and_the_target_carries_the_pin_id(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id);

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-9']),
        ]);

        $response = $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$row->getKey()],
                'body' => 'New flue liner, same day.',
                'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
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
        $t = $this->provisionTenant('A1-Group');
        $ok = $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $bad = $this->connection($t, 'company', $t['company']->id, 'A-1 Roofing', 'pin-b');

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins' => Http::sequence()
                ->push(['id' => 'pin-9'])
                ->push(['message' => 'That board is archived.'], 400),
        ]);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, [
                'connections' => [$ok->getKey(), $bad->getKey()],
                'body' => 'Two accounts, one of them unhappy.',
                'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
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
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
        ]));

        $mine = $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $theirs = $this->connection($t, 'company', $premier->id, 'Premier', 'pin-b');

        Http::fake([
            'api.pinterest.com/v5/boards*' => Http::response(['items' => [['id' => 'board-1']]]),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-9']),
        ]);

        foreach ([$mine, $theirs] as $connection) {
            $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
                'connections' => [$connection->getKey()],
                'body' => 'From '.$connection->display_name,
                'media' => UploadedFile::fake()->create('shot.png', 12, 'image/png'),
            ])->assertCreated();
        }

        $this->lookingAt($t['admin'], 'company', $t['company']->id);

        $bodies = $this->signedInAs($t['admin']->fresh())
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->json('data.*.body');

        $this->assertSame(['From A-1 Chimney'], $bodies);
    }

    /**
     * The tiles count every post this view can see, not the twenty drawn, and
     * `status` filters on the server (Mia, 2026-10-02, Posts layout A). A
     * sibling's failure is in no tile here.
     */
    public function test_the_tiles_count_past_the_twenty_drawn_and_filter_by_status(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
        ]));
        $mine = $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        $other = $this->connection($t, 'company', $t['company']->id, 'A-1 Boards', 'pin-c');
        $theirs = $this->connection($t, 'company', $premier->id, 'Premier', 'pin-b');

        $this->inTenant($t['org'], function () use ($t, $mine, $other, $theirs) {
            $post = function (array $targets) use ($t) {
                $row = SocialPost::create(['body' => 'x', 'created_by' => $t['admin']->id]);
                foreach ($targets as [$connection, $status]) {
                    $row->targets()->create([
                        'social_connection_id' => $connection->getKey(),
                        'status' => $status,
                    ]);
                }
            };

            for ($i = 0; $i < 22; $i++) {
                $post([[$mine, 'sent']]);
            }
            $post([[$mine, 'scheduled'], [$other, 'scheduled']]);
            $post([[$mine, 'sent'], [$other, 'failed']]);
            $post([[$mine, 'queued']]);
            // Failed only at the sibling: here it reads as sent.
            $post([[$mine, 'sent'], [$theirs, 'failed']]);
            $post([[$theirs, 'failed']]);
        });

        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $admin = $t['admin']->fresh();

        $this->signedInAs($admin)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.tiles', ['scheduled' => 1, 'sent' => 23, 'failed' => 1]);

        $failed = $this->signedInAs($admin)
            ->getJson(self::ENDPOINT.'?status=failed')
            ->assertOk()
            ->assertJsonPath('meta.tiles.sent', 23)
            ->json('data');
        $this->assertCount(1, $failed);
        $this->assertEqualsCanonicalizing(['sent', 'failed'], array_column($failed[0]['targets'], 'status'));

        $this->signedInAs($admin)
            ->getJson(self::ENDPOINT.'?status=scheduled')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.targets.0.status', 'scheduled');

        // An unknown status is ignored rather than emptying the list.
        $this->signedInAs($admin)
            ->getJson(self::ENDPOINT.'?status=nonsense')
            ->assertJsonCount(20, 'data');
    }

    public function test_the_account_list_says_which_accounts_can_be_posted_to(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->connection($t, 'company', $t['company']->id, 'A-1 Chimney', 'pin-a');
        // Every network has a publisher since 2026-10-09; X stands in for the newest.
        $this->connection($t, 'company', $t['company']->id, 'A-1 on X', 'x-a', 'x');

        $rows = collect($this->signedInAs($t['admin'])
            ->getJson('/api/v1/social/connections')
            ->assertOk()
            ->json('data'))
            ->keyBy('provider');

        $this->assertTrue($rows['pinterest']['can_publish']);
        $this->assertNull($rows['pinterest']['cannot_publish_because']);

        $this->assertTrue($rows['x']['can_publish']);
        $this->assertNull($rows['x']['cannot_publish_because']);
    }

    public function test_a_facebook_post_carries_the_link_facebook_gives_for_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, remoteId: 'page-1', provider: 'facebook_page');

        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'page-1_post-2']),
            // Read back, never built from the id (pages_read_engagement).
            'graph.facebook.com/*/page-1_post-2*' => Http::response(['permalink_url' => 'https://www.facebook.com/a1chimney/posts/2']),
        ]);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Chimney season is here.'])
            ->assertCreated()
            ->assertJsonPath('data.targets.0.url', 'https://www.facebook.com/a1chimney/posts/2');
    }

    public function test_a_post_whose_link_cannot_be_read_is_still_sent(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, remoteId: 'page-1', provider: 'facebook_page');

        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'page-1_post-2']),
            'graph.facebook.com/*/page-1_post-2*' => Http::response(['error' => ['message' => 'nope']], 500),
        ]);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Chimney season is here.'])
            ->assertCreated()
            ->assertJsonPath('data.targets.0.status', SocialPostTarget::STATUS_SENT)
            ->assertJsonPath('data.targets.0.url', null);
    }

    public function test_a_failed_account_can_be_sent_again_and_lands(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, remoteId: 'page-1', provider: 'facebook_page');

        Http::fake([
            'graph.facebook.com/*/feed' => Http::sequence()
                ->push(['error' => ['message' => 'Temporarily down']], 500)
                ->push(['id' => 'page-1_post-3']),
            'graph.facebook.com/*/page-1_post-3*' => Http::response(['permalink_url' => 'https://www.facebook.com/a1chimney/posts/3']),
        ]);

        $sent = $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Chimney season is here.'])
            ->assertJsonPath('data.targets.0.status', SocialPostTarget::STATUS_FAILED);

        $this->signedInAs($t['admin'])
            ->postJson($this->retryUrl($sent->json('data.id'), $sent->json('data.targets.0.id')))
            ->assertOk()
            ->assertJsonPath('data.status', SocialPostTarget::STATUS_SENT)
            ->assertJsonPath('data.failure', null)
            ->assertJsonPath('data.url', 'https://www.facebook.com/a1chimney/posts/3');
    }

    public function test_a_sent_account_cannot_be_sent_twice(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, remoteId: 'page-1', provider: 'facebook_page');

        Http::fake([
            'graph.facebook.com/*/feed' => Http::response(['id' => 'page-1_post-2']),
            'graph.facebook.com/*/page-1_post-2*' => Http::response(['permalink_url' => 'https://www.facebook.com/a1chimney/posts/2']),
        ]);

        $sent = $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Chimney season is here.'])
            ->assertJsonPath('data.targets.0.status', SocialPostTarget::STATUS_SENT);

        $this->signedInAs($t['admin'])
            ->postJson($this->retryUrl($sent->json('data.id'), $sent->json('data.targets.0.id')))
            ->assertStatus(422)
            ->assertJsonValidationErrors('target');

        Http::assertSentCount(2); // the one post and its link, nothing more
    }

    public function test_a_retry_is_refused_to_a_reader_and_hidden_from_a_sibling(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->connection($t, 'company', $t['company']->id, remoteId: 'page-1', provider: 'facebook_page');
        Http::fake(['graph.facebook.com/*/feed' => Http::response(['error' => ['message' => 'down']], 500)]);

        $sent = $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$row->getKey()], 'body' => 'Hello']);
        $url = $this->retryUrl($sent->json('data.id'), $sent->json('data.targets.0.id'));

        $this->signedInAs($this->userWithRole($t, 'Reader', ['marketing.view']))
            ->postJson($url)
            ->assertForbidden();

        $premier = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Premier Garage Repair',
        ]));
        $this->lookingAt($t['admin'], 'company', $premier->id);

        $this->signedInAs($t['admin']->fresh())
            ->postJson($url)
            ->assertNotFound();

        $this->inTenant($t['org'], fn () => $this->assertSame(
            SocialPostTarget::STATUS_FAILED,
            SocialPostTarget::query()->sole()->status,
        ));
    }

    private function retryUrl(string $post, string $target): string
    {
        return self::ENDPOINT."/{$post}/targets/{$target}/retry";
    }

    /**
     * Jose, 2026-10-15: "if I choose 7 accounts but 2 aren't able to post the
     * other 5 should still go through". Pinterest cannot take words alone;
     * Facebook can. Facebook goes out, and Pinterest is written as FAILED with
     * the reason, so it is named on the dashboard instead of vanishing.
     */
    public function test_one_account_that_cannot_take_the_post_does_not_stop_the_others(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $pin = $this->connection($t, 'company', $t['company']->id, 'Pins', 'pin-1', 'pinterest');
        $page = $this->connection($t, 'company', $t['company']->id, 'Page', 'page-1', 'facebook_page');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1'])]);

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$pin->getKey(), $page->getKey()], 'body' => 'Words only'])
            ->assertCreated();

        $this->inTenant($t['org'], function () use ($pin, $page) {
            $byConnection = SocialPostTarget::query()->get()->keyBy('social_connection_id');
            $this->assertSame(SocialPostTarget::STATUS_SENT, $byConnection[$page->getKey()]->status);
            $this->assertSame(SocialPostTarget::STATUS_FAILED, $byConnection[$pin->getKey()]->status);
            $this->assertNotEmpty($byConnection[$pin->getKey()]->failure);
        });
    }

    public function test_a_post_no_ticked_account_can_take_is_refused_whole(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $pin = $this->connection($t, 'company', $t['company']->id, 'Pins', 'pin-1', 'pinterest');
        Http::fake();

        $this->signedInAs($t['admin'])
            ->post(self::ENDPOINT, ['connections' => [$pin->getKey()], 'body' => 'Words only'])
            ->assertStatus(422);

        $this->assertNothingWasWritten($t);
    }

    /** A post nobody has yet: its words and time can change, and it can be cancelled. */
    public function test_a_scheduled_post_can_be_edited_rescheduled_and_cancelled(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'company', $t['company']->id, 'Page', 'page-1', 'facebook_page');
        Http::fake();

        $id = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$page->getKey()], 'body' => 'Autumn', 'scheduled_for' => '2099-03-04 09:00',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, [
            'body' => 'Autumn sweeps', 'scheduled_for' => '2099-03-05 10:00',
        ])->assertOk()->assertJsonPath('data.body', 'Autumn sweeps');

        $this->inTenant($t['org'], fn () => $this->assertStringStartsWith(
            '2099-03-05 10:00', (string) SocialPost::query()->find($id)->scheduled_for,
        ));

        $this->signedInAs($t['admin'])->deleteJson(self::ENDPOINT.'/'.$id)->assertOk();
        $this->assertNothingWasWritten($t);
        Http::assertNothingSent();
    }

    public function test_a_post_that_went_out_cannot_be_edited_or_cancelled(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'company', $t['company']->id, 'Page', 'page-1', 'facebook_page');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1'])]);

        $id = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$page->getKey()], 'body' => 'Now',
        ])->assertCreated()->json('data.id');

        $this->signedInAs($t['admin'])->patchJson(self::ENDPOINT.'/'.$id, ['body' => 'Changed'])->assertStatus(422);
        $this->signedInAs($t['admin'])->deleteJson(self::ENDPOINT.'/'.$id)->assertStatus(422);
    }

    /** Taken down from Facebook, and marked removed - the link goes with it. */
    public function test_a_sent_post_can_be_deleted_from_a_network_that_allows_it(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $page = $this->connection($t, 'company', $t['company']->id, 'Page', 'page-1', 'facebook_page');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1', 'success' => true])]);

        $data = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$page->getKey()], 'body' => 'Now',
        ])->json('data');
        $target = $data['targets'][0]['id'];
        $this->assertTrue($data['targets'][0]['can_delete']);

        $this->signedInAs($t['admin'])
            ->deleteJson(self::ENDPOINT."/{$data['id']}/targets/{$target}/remote")
            ->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'fb-post-1'));
        $this->inTenant($t['org'], fn () => $this->assertSame(
            SocialPostTarget::STATUS_REMOVED, SocialPostTarget::query()->find($target)->status,
        ));
    }

    /** Instagram has no delete in its API: said so, nothing sent. */
    public function test_delete_is_refused_in_words_where_the_network_has_none(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $ig = $this->connection($t, 'company', $t['company']->id, 'IG', 'ig-1', 'instagram_professional');

        $target = $this->inTenant($t['org'], function () use ($t, $ig) {
            $post = SocialPost::create(['body' => 'x', 'created_by' => $t['admin']->id]);
            $target = SocialPostTarget::create(['social_post_id' => $post->id, 'social_connection_id' => $ig->id, 'status' => 'queued']);
            $target->markSent('ig-media-1');

            return $target;
        });
        Http::fake();

        $this->signedInAs($t['admin'])
            ->deleteJson(self::ENDPOINT."/{$target->social_post_id}/targets/{$target->id}/remote")
            ->assertStatus(422)
            ->assertJsonPath('errors.target.0', fn (string $m) => str_contains($m, 'does not let EzBizi delete'));
        Http::assertNothingSent();
    }

    public function test_a_failed_account_can_be_dismissed(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $pin = $this->connection($t, 'company', $t['company']->id, 'Pins', 'pin-1', 'pinterest');
        $page = $this->connection($t, 'company', $t['company']->id, 'Page', 'page-1', 'facebook_page');
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => 'fb-post-1'])]);

        $data = $this->signedInAs($t['admin'])->post(self::ENDPOINT, [
            'connections' => [$pin->getKey(), $page->getKey()], 'body' => 'Words only',
        ])->json('data');
        $failed = collect($data['targets'])->firstWhere('status', 'failed')['id'];
        $sent = collect($data['targets'])->firstWhere('status', 'sent')['id'];

        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$data['id']}/targets/{$sent}/dismiss")->assertStatus(422);
        $this->signedInAs($t['admin'])->postJson(self::ENDPOINT."/{$data['id']}/targets/{$failed}/dismiss")->assertOk();

        $this->inTenant($t['org'], fn () => $this->assertSame([$sent], SocialPostTarget::query()->pluck('id')->all()));
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
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'provider' => $provider,
                'remote_id' => $remoteId,
                'display_name' => $name,
                'handle' => strtolower(str_replace(' ', '', $name)),
            ]);
            $row->connect([
                'refresh_token' => 'pinr-a-refresh-token',
                'access_token' => 'pina-an-access-token',
            ]);
            $row->save();

            return $row;
        });
    }

    private function lookingAt(User $user, string $type, string $id): void
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
