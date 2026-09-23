<?php

namespace Tests\Feature\Api;

use App\Services\A1Leads\A1LeadsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The A1 Leads bridge: read-only, and honest about how old its data is.
 *
 * ── The failure these are aimed at ───────────────────────────────
 *
 * The data is a daily snapshot of somebody else's system. A refresh that stops
 * running leaves yesterday's file in place and every request still answers 200,
 * so the tab keeps drawing a perfectly plausible list of reviews that is a week
 * old. Nothing errors. The only thing that can catch it is the snapshot's own
 * capture time, travelling with the data and measured against the clock.
 *
 * The second failure is the opposite shape and worse: an unreachable source
 * rendering as an empty list, which says A-1 Chimney has no reviews. It has
 * thirteen thousand.
 */
class A1LeadsGbpSourceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/a1leads-gbp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.a1leads.base_url', 'http://127.0.0.1:3456');
        config()->set('services.a1leads.api_key', 'test-partner-key');
        config()->set('services.a1leads.stale_after_hours', 30);
        config()->set('services.a1leads.receipt', $this->receiptFile('2026-09-23T15:01:07.302Z'));
    }

    /** A receipt on disk, because that is how the real one arrives. */
    private function receiptFile(string $capturedAt, ?string $installedAt = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'a1receipt');

        file_put_contents($path, json_encode([
            'installed_generation' => '20260923T150107Z-23510eb0',
            'source_release_sha' => 'eb72dbcd42b88dca8de2281a4847f09fb8d61472',
            'snapshot_captured_at' => $capturedAt,
            'installed_at' => $installedAt ?? $capturedAt,
        ]));

        return $path;
    }

    private function fakeA1Leads(): void
    {
        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1'], 200),
            '*/api/v1/reviews/summary' => Http::response(['data' => ['total' => 13169]], 200),
            '*/api/v1/reviews*' => Http::response([
                'data' => [['google_review_name' => 'accounts/a/locations/l/reviews/r-1', 'rating' => 5]],
                'meta' => ['total' => 13169],
            ], 200),
            '*/api/v1/gbp/health' => Http::response(['data' => ['locations' => 3]], 200),
        ]);
    }

    /** THE POSITIVE CONTROL, first: the tab reads and says when the data is from. */
    public function test_it_returns_reviews_with_the_snapshot_date_beside_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $data = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertTrue($data['source']['configured']);
        $this->assertTrue($data['source']['reachable']);
        $this->assertFalse($data['source']['stale']);
        $this->assertSame('20260923T150107Z-23510eb0', $data['source']['generation']);
        $this->assertCount(1, $data['reviews']);
        $this->assertSame(13169, $data['summary']['total']);
    }

    /**
     * A snapshot older than the window is STALE while still answering 200.
     *
     * This is the case the whole design exists for. Nothing about the response
     * changes except the flag: the reviews are still there, still plausible,
     * and a day and a half out of date.
     */
    public function test_a_snapshot_past_the_window_is_marked_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25T02:00:00Z'));
        config()->set('services.a1leads.receipt', $this->receiptFile('2026-09-23T15:01:07.302Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Bravo');
        $reader = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertTrue($source['stale'], 'a snapshot older than the window must say so');
        $this->assertGreaterThan(30, $source['hours_old']);
        $this->assertTrue($source['reachable'], 'and it is stale rather than unreachable');
    }

    /**
     * Age is measured from the CAPTURE, not the install.
     *
     * The discriminating case: a refresh that reinstalls the same old snapshot
     * has a fresh install time and stale data. Measuring the wrong one makes
     * exactly that look healthy.
     */
    public function test_a_fresh_install_of_old_data_is_still_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25T02:00:00Z'));
        config()->set('services.a1leads.receipt', $this->receiptFile(
            '2026-09-23T15:01:07.302Z',
            '2026-09-25T01:59:00Z',
        ));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Charlie');
        $reader = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertTrue($source['stale'], 'installed a minute ago, captured a day and a half ago');
    }

    /**
     * An unreachable source is said out loud, never drawn as an empty business.
     */
    public function test_a_refusal_is_reported_as_a_source_problem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1'], 200),
            // What the consumer policy answers for a route it does not list.
            '*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $t = $this->provisionTenant('Delta');
        $reader = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $data = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertFalse($data['source']['reachable']);
        $this->assertStringContainsString('403', (string) $data['source']['because']);
        $this->assertSame([], $data['reviews']);
        $this->assertNull($data['summary']);
    }

    /** With no key at all it says so, rather than pretending to be empty. */
    public function test_an_unconfigured_environment_says_so(): void
    {
        config()->set('services.a1leads.api_key', null);

        $t = $this->provisionTenant('Echo');
        $reader = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertFalse($source['configured']);
        $this->assertStringContainsString('key', (string) $source['because']);
    }

    /** The tab is its own grant, and our own modules do not open it. */
    public function test_the_permission_is_its_own(): void
    {
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Foxtrot');

        $this->signedInAs($this->userWithRole($t, 'Reviews', ['reviews.view']))
            ->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    /**
     * THE STRUCTURAL REFUSAL: this client cannot be made to write.
     *
     * The host refuses mutations two ways already, and neither is ours. This
     * asserts the third, which is: there is no method here that can produce
     * anything but a GET, and a route outside the allowed five fails before it
     * reaches the network rather than as somebody else's 403.
     */
    public function test_the_client_cannot_reach_a_route_it_was_not_granted(): void
    {
        $this->fakeA1Leads();

        $client = app(A1LeadsClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no route');

        // Every public method is a named read; this is the only way to ask for
        // something else, and it is refused here rather than over the wire.
        (fn () => $this->get('leads'))->call($client);
    }

    /** And the key travels in a header, never in the URL. */
    public function test_the_key_is_never_put_in_the_query_string(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        app(A1LeadsClient::class)->reviewsSummary();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/reviews/summary')) {
                return true;
            }

            return ! str_contains($request->url(), 'test-partner-key')
                && $request->header('X-API-Key')[0] === 'test-partner-key';
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
