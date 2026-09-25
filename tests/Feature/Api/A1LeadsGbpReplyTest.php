<?php

namespace Tests\Feature\Api;

use App\Models\A1LeadsReplyReceipt;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Exact-review replies use a separate live credential, never the daily reader. */
class A1LeadsGbpReplyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const REVIEW = 'accounts/a/locations/l/reviews/r-1';

    private const LIVE = 'http://127.0.0.1:3457/api/v1/reviews/reply/by-name';

    private function setupBridge(array $tenant): void
    {
        config()->set('services.a1leads.organization_id', $tenant['org']->id);
        config()->set('services.a1leads.company_id', $tenant['company']->id);
        config()->set('services.a1leads.live_reply_url', 'http://127.0.0.1:3457');
        config()->set('services.a1leads.live_reply_key', 'dedicated-reply-key');
        config()->set('services.a1leads.base_url', 'http://127.0.0.1:3456');
        config()->set('services.a1leads.api_key', 'daily-snapshot-key');
    }

    private function operator(array $tenant)
    {
        return $this->userWithRole($tenant, 'GBP Operator', [
            'a1leads_gbp.view', 'a1leads_gbp.reply',
        ]);
    }

    public function test_live_preflight_uses_exact_name_and_separate_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            self::LIVE.'*' => Http::response(['data' => [
                'googleReviewName' => self::REVIEW,
                'reviewUpdatedAt' => '2026-09-24T12:00:00Z',
                'canReply' => true,
            ]]),
        ]);
        Http::preventStrayRequests();

        $data = $this->signedInAs($this->operator($tenant))
            ->getJson('/api/v1/a1leads-gbp/reply/preflight?googleReviewName='.rawurlencode(self::REVIEW))
            ->assertOk()->json('data');

        $this->assertSame(self::REVIEW, $data['googleReviewName']);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), self::LIVE)
            && $request->hasHeader('X-API-Key', 'dedicated-reply-key')
            && $request['businessSlug'] === 'a1-chimney'
            && $request['googleReviewName'] === self::REVIEW);
    }

    public function test_post_derives_actor_and_business_on_server(): void
    {
        $tenant = $this->provisionTenant('Bravo');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            self::LIVE => Http::response(['data' => [
                'ok' => true, 'reviewId' => 4, 'googleReviewName' => self::REVIEW,
                'reply_status' => 'sent', 'text' => 'Thank you for letting us know.',
                'sentAt' => '2026-09-24T12:01:00Z',
            ]]),
        ]);
        Http::preventStrayRequests();
        $actor = $this->operator($tenant);

        $this->signedInAs($actor)->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'Thank you for letting us know.',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
            'actorId' => 'forged-browser-id',
            'businessSlug' => 'everdoors',
        ])->assertOk()->assertJsonPath('data.reply_status', 'sent');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === self::LIVE
            && $request->hasHeader('X-API-Key', 'dedicated-reply-key')
            && $request['businessSlug'] === 'a1-chimney'
            && $request['actorId'] === $actor->id
            && $request['googleReviewName'] === self::REVIEW
            && $request['expectedReviewUpdatedAt'] === '2026-09-24T12:00:00Z');
    }

    /**
     * Without the A1Leads GBP permission there is no reply. Replying needs
     * its own permission (Jose, 2026-09-25) - so a user who may SEE the tab,
     * and holds EzBizi's own Reviews edit besides, is refused, and Google
     * hears nothing.
     */
    public function test_without_the_reply_permission_nothing_is_published(): void
    {
        $tenant = $this->provisionTenant('Charlie');
        $this->setupBridge($tenant);
        Http::fake();
        $viewer = $this->userWithRole($tenant, 'Viewer', ['a1leads_gbp.view', 'reviews.view', 'reviews.edit']);

        $this->signedInAs($viewer)->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'A reply',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_wrong_company_or_missing_key_fails_before_live_call(): void
    {
        $tenant = $this->provisionTenant('Delta');
        $this->setupBridge($tenant);
        Http::fake(['http://127.0.0.1:3456/_info' => Http::response([])]);
        Http::preventStrayRequests();
        $actor = $this->operator($tenant);
        config()->set('services.a1leads.company_id', '00000000-0000-4000-8000-000000000001');

        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp/reply/preflight?googleReviewName='.rawurlencode(self::REVIEW))
            ->assertForbidden();
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::LIVE));

        config()->set('services.a1leads.company_id', $tenant['company']->id);
        config()->set('services.a1leads.live_reply_key', null);
        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp/reply/preflight?googleReviewName='.rawurlencode(self::REVIEW))
            ->assertStatus(503);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::LIVE));
    }

    public function test_upstream_conflict_is_not_disguised_as_success(): void
    {
        $tenant = $this->provisionTenant('Echo');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response([]),
            self::LIVE => Http::response(['error' => [
                'code' => 'review_changed',
                'message' => 'The reviewer edited this review. Check it again.',
            ]], 409),
        ]);
        Http::preventStrayRequests();

        $this->signedInAs($this->operator($tenant))->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'A reply',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
        ])->assertStatus(409)->assertJsonPath('error.code', 'review_changed');
    }

    public function test_confirmed_live_reply_overrides_an_old_unanswered_snapshot(): void
    {
        $tenant = $this->provisionTenant('Foxtrot');
        $this->setupBridge($tenant);
        Http::fake([
            self::LIVE => Http::response(['data' => [
                'ok' => true, 'reviewId' => 4, 'googleReviewName' => self::REVIEW,
                'reply_status' => 'sent', 'text' => 'We are looking into this.',
                'sentAt' => '2026-09-24T12:01:00Z',
            ]]),
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            'http://127.0.0.1:3456/api/v1/reviews/summary' => Http::response(['data' => ['total' => 1]]),
            'http://127.0.0.1:3456/api/v1/reviews*' => Http::response(['data' => [[
                'google_review_name' => self::REVIEW,
                'reviewer_name' => 'Dana', 'rating' => 1,
                'reply' => null, 'reply_at' => null,
                'review_status' => ['visibility' => 'live', 'reply' => 'none'],
            ]], 'meta' => ['total' => 1]]),
            'http://127.0.0.1:3456/api/v1/gbp/health' => Http::response(['data' => []]),
            'http://127.0.0.1:3456/api/v1/gbp/locations' => Http::response(['data' => []]),
        ]);
        Http::preventStrayRequests();
        $actor = $this->operator($tenant);

        $this->signedInAs($actor)->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'We are looking into this.',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
        ])->assertOk()->assertJsonPath('data.receiptPersisted', true);

        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp')
            ->assertOk()
            ->assertJsonPath('data.reviews.0.reply', 'We are looking into this.')
            ->assertJsonPath('data.reviews.0.review_status.reply', 'sent');
    }

    /**
     * The tab says WHY there is no Reply button. Replying switched off and
     * A-1 Chimney not in view are different causes with different remedies,
     * and the screen once named the second for both.
     */
    public function test_the_tab_names_why_replying_is_unavailable(): void
    {
        $tenant = $this->provisionTenant('Juliet');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            'http://127.0.0.1:3456/api/v1/reviews/summary' => Http::response(['data' => ['total' => 0]]),
            'http://127.0.0.1:3456/api/v1/reviews*' => Http::response(['data' => [], 'meta' => ['total' => 0]]),
            'http://127.0.0.1:3456/api/v1/gbp/health' => Http::response(['data' => []]),
            'http://127.0.0.1:3456/api/v1/gbp/locations' => Http::response(['data' => []]),
        ]);
        $actor = $this->operator($tenant);

        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp')->assertOk()
            ->assertJsonPath('data.reply_available', true)
            ->assertJsonPath('data.reply_unavailable_because', null);

        // Another company in view no longer reaches the reply question at
        // all: the reviews are A-1 Chimney's and are not read (Jose, 24 Sep).
        config()->set('services.a1leads.company_id', '00000000-0000-4000-8000-000000000001');
        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp')->assertOk()
            ->assertJsonPath('data.source.configured', false)
            ->assertJsonPath('data.reply_available', false)
            ->assertJsonPath('data.reviews', []);

        // Seeing the tab is not replying: a viewer is told why.
        $viewer = $this->userWithRole($tenant, 'Viewer', ['a1leads_gbp.view']);
        config()->set('services.a1leads.company_id', $tenant['company']->id);
        $this->signedInAs($viewer)->getJson('/api/v1/a1leads-gbp')->assertOk()
            ->assertJsonPath('data.source.configured', true)
            ->assertJsonPath('data.reply_available', false)
            ->assertJsonPath('data.reply_unavailable_because', 'no_permission');

        // Production today: A-1 Chimney in view, but no live key.
        config()->set('services.a1leads.company_id', $tenant['company']->id);
        config()->set('services.a1leads.live_reply_key', null);
        $this->signedInAs($actor)->getJson('/api/v1/a1leads-gbp')->assertOk()
            ->assertJsonPath('data.reply_available', false)
            ->assertJsonPath('data.reply_unavailable_because', 'not_switched_on');
    }

    /**
     * An attempt WITHOUT the permission is refused before anything is sent -
     * and is audited, which a route-middleware refusal could not be.
     */
    public function test_an_attempt_without_the_grant_is_refused_and_audited(): void
    {
        $tenant = $this->provisionTenant('Hotel');
        $this->setupBridge($tenant);
        Http::fake();
        $viewer = $this->userWithRole($tenant, 'Viewer', ['a1leads_gbp.view']);

        $this->signedInAs($viewer)->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'A reply',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
        ])->assertForbidden()->assertJsonPath('error.code', 'no_permission');

        Http::assertNothingSent();

        $event = $this->inTenant($tenant['org'], fn () => AuditEvent::query()
            ->where('action', 'a1leads_gbp.review_reply_failed')->sole());
        $this->assertSame($viewer->getKey(), $event->actor_id);
        $this->assertSame('no_permission', $event->metadata['code']);
    }

    /**
     * A connection that drops DURING the POST is an unknown outcome, not a
     * failure: Google may have accepted the reply. It is reported as such,
     * no receipt is written (there is no confirmed send to show), and the
     * attempt is audited so nobody reads the silence as "nothing happened".
     */
    public function test_a_dropped_post_is_an_unknown_outcome_and_is_audited(): void
    {
        $tenant = $this->provisionTenant('India');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            self::LIVE => fn () => throw new ConnectionException('reset'),
        ]);

        $this->signedInAs($this->operator($tenant))->postJson('/api/v1/a1leads-gbp/reply', [
            'googleReviewName' => self::REVIEW,
            'text' => 'A reply',
            'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
        ])->assertStatus(503)->assertJsonPath('error.code', 'reply_outcome_unknown');

        $this->assertSame(0, $this->inTenant($tenant['org'], fn () => A1LeadsReplyReceipt::query()->count()));
        $this->assertSame('reply_outcome_unknown', $this->inTenant($tenant['org'], fn () => AuditEvent::query()
            ->where('action', 'a1leads_gbp.review_reply_failed')->sole()->metadata['code']));
    }

    /**
     * A public reply accepted by Google is on OUR audit trail; a refused one is
     * not. Added by EzBizi when the handoff was merged: the reply is public,
     * permanent and under the business's name, so who sent it has to be
     * answerable here and not only in A1 Leads' log - and the log must not
     * claim a reply Google turned down.
     */
    public function test_an_accepted_reply_is_audited_and_a_refused_one_is_not(): void
    {
        $tenant = $this->provisionTenant('Golf');
        $this->setupBridge($tenant);
        Http::fake([
            'http://127.0.0.1:3456/_info' => Http::response(['version' => '1.10.1']),
            self::LIVE => Http::sequence()
                ->push(['data' => [
                    'ok' => true, 'googleReviewName' => self::REVIEW,
                    'text' => 'Thank you for telling us.', 'sentAt' => '2026-09-24T12:01:00Z',
                ]], 200)
                ->push(['error' => ['code' => 'review_changed', 'message' => 'Edited.']], 409),
        ]);
        Http::preventStrayRequests();
        $actor = $this->operator($tenant);

        foreach ([200, 409] as $status) {
            $this->signedInAs($actor)->postJson('/api/v1/a1leads-gbp/reply', [
                'googleReviewName' => self::REVIEW,
                'text' => 'Thank you for telling us.',
                'expectedReviewUpdatedAt' => '2026-09-24T12:00:00Z',
            ])->assertStatus($status);
        }

        $events = $this->inTenant($tenant['org'], fn () => AuditEvent::query()
            ->where('action', 'a1leads_gbp.review_replied')->get());

        $this->assertCount(1, $events, 'the accepted one, not the refused one');

        // And the refused one IS on the trail - as a failed attempt, with its
        // code, and without the words: an unpublished draft is not the
        // business's public statement.
        $failed = $this->inTenant($tenant['org'], fn () => AuditEvent::query()
            ->where('action', 'a1leads_gbp.review_reply_failed')->get());
        $this->assertCount(1, $failed);
        $this->assertSame('review_changed', $failed[0]->metadata['code']);
        $this->assertSame(409, $failed[0]->metadata['status']);
        $this->assertSame(self::REVIEW, $failed[0]->metadata['google_review_name']);
        $this->assertStringNotContainsString('Thank you for telling us', json_encode([$failed[0]->changes, $failed[0]->metadata]));
        $this->assertSame($actor->getKey(), $events[0]->actor_id);
        $this->assertSame(self::REVIEW, $events[0]->metadata['google_review_name']);
        $this->assertSame(['reply' => 'Thank you for telling us.'], $events[0]->changes);
    }
}
