<?php

namespace Tests\Feature\Api;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Workiz import: from now on, not from the beginning, and not twice.
 *
 * ── The three decisions these hold ───────────────────────────────
 *
 * Jose, 2026-09-23: *"we don't need the past stuff, just start from now"*, and
 * *"whatever you build must not put us in a dependency state"*. Half of A-1's
 * Workiz volume is Google Local Services and Thumbtack work, which EzBizi
 * already ingests directly.
 *
 * So: the first run imports nothing and only marks where now was; the channels
 * we already hold are skipped; and a dispatcher's decision about a lead is
 * never walked back by a later run.
 */
class ImportWorkizLeadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $row = SocialConnection::create([
                'organization_id' => $t['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $t['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => 'workiz-branch-1',
                'display_name' => 'A1 Chimney - Hallandale',
            ]);
            $row->connect(['api_token' => 'tok-good']);
            $row->save();

            return $row;
        });
    }

    /** @param array<int, array<string, mixed>> $leads */
    private function workizReturns(array $leads): void
    {
        Http::fake([
            'api.workiz.com/*' => Http::response(['flag' => true, 'data' => $leads], 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'UUID' => 'wz-'.bin2hex(random_bytes(4)),
            'SerialId' => 4021,
            'LeadDateTime' => '2026-09-23 09:15:00',
            'Status' => 'Submitted',
            'SubStatus' => '',
            'Phone' => '+1 (555) 123-4567',
            'Email' => 'dana@example.com',
            'FirstName' => 'Dana',
            'LastName' => 'Whitfield',
            'Company' => '',
            'JobType' => 'Chimney Sweep',
            'JobSource' => 'Angi Sweep',
            'LeadNotes' => 'Wants a survey before winter.',
        ], $overrides);
    }

    /**
     * THE FIRST RUN IMPORTS NOTHING, and that is the whole of "start from now".
     *
     * The assertion is not only that no rows landed: it is that the request
     * asked Workiz for TODAY. A version that fetched everything and filtered
     * afterwards would satisfy an empty table while pulling thirty-one thousand
     * rows across nine branches every night.
     */
    public function test_the_first_run_imports_nothing_and_asks_only_for_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Alpha');
        $this->connection($t);
        $this->workizReturns([$this->payload(['LeadDateTime' => '2024-01-05 08:00:00'])]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $this->assertSame(0, $this->inTenant($t['org'], fn () => Lead::query()
            ->where('source', Lead::SOURCE_WORKIZ)->count()));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-09-23'));
    }

    /**
     * "Now" is 2026-09-23 or when the branch connected, not each run's now. Read live, that
     * moved every minute: a branch with no Workiz lead yet skipped every lead
     * as older than the run (New Jersey's 2026-10-07 enquiry, 2026-10-08).
     */
    public function test_a_branch_with_no_lead_yet_imports_one_made_after_it_connected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Charlie');
        $connection = $this->connection($t);
        $this->inTenant($t['org'], fn () => $connection->forceFill(['created_at' => Carbon::parse('2026-09-15 14:15:22', 'UTC')])->save());
        $this->workizReturns([
            $this->payload(['UUID' => 'wz-after', 'LeadDateTime' => '2026-10-07 12:57:22']),
            $this->payload(['UUID' => 'wz-before', 'LeadDateTime' => '2026-09-20 09:00:00']),
        ]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $this->assertSame(['wz-after'], $this->inTenant($t['org'], fn () => Lead::query()
            ->where('source', Lead::SOURCE_WORKIZ)->pluck('remote_id')->all()));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-09-23'));
    }

    /**
     * A lead newer than the watermark is imported, with the branch's clock
     * applied.
     *
     * The timezone half is the one that fails silently: Workiz sends a naive
     * `Y-m-d H:i:s` and never sends a zone, so written straight into a UTC
     * column a 09:15 enquiry lands at 09:15 UTC — five hours early in New York,
     * on the wrong day near midnight.
     */
    public function test_a_new_lead_is_imported_in_the_branch_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Bravo');
        $connection = $this->connection($t);

        $this->inTenant($t['org'], fn () => $t['franchise']->forceFill([
            'timezone' => 'America/New_York',
        ])->save());

        // An older row establishes the watermark, so this is not a first run.
        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'franchise_id' => $t['franchise']->id,
            'connection_id' => $connection->getKey(),
            'source' => Lead::SOURCE_WORKIZ,
            'remote_id' => 'wz-seed',
            'contact_name' => 'Seed',
            'status' => Lead::STATUS_NEW,
            'received_at' => Carbon::parse('2026-09-22 12:00:00', 'UTC'),
        ]));

        $this->workizReturns([$this->payload([
            'UUID' => 'wz-new',
            'LeadDateTime' => '2026-09-23 09:15:00',
        ])]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()
            ->where('remote_id', 'wz-new')->firstOrFail());

        $this->assertSame('Dana Whitfield', $lead->contact_name);
        $this->assertSame($t['franchise']->id, $lead->franchise_id);
        $this->assertSame(
            '2026-09-23 13:15:00',
            $lead->received_at->utc()->format('Y-m-d H:i:s'),
            '09:15 in New York is 13:15 UTC - a naive string written straight in lands five hours early',
        );
    }

    /**
     * The channels EzBizi already ingests are skipped, and the rest are not.
     *
     * Asserted in both directions in one run, because a test that only checked
     * the skip would pass for an import that had stopped working entirely.
     */
    public function test_it_skips_the_channels_we_already_ingest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Charlie');
        $connection = $this->connection($t);
        $this->seedWatermark($t, $connection);

        $this->workizReturns([
            $this->payload(['UUID' => 'wz-lsa', 'JobSource' => 'LS Ads']),
            $this->payload(['UUID' => 'wz-tt', 'JobSource' => 'Thumbtack']),
            $this->payload(['UUID' => 'wz-angi', 'JobSource' => 'Angi Sweep']),
        ]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $ids = $this->inTenant($t['org'], fn () => Lead::query()
            ->where('source', Lead::SOURCE_WORKIZ)
            ->where('remote_id', '!=', 'wz-seed')
            ->pluck('remote_id')->all());

        $this->assertSame(['wz-angi'], $ids);
    }

    /**
     * An unknown channel is IMPORTED, and a dirty spelling is still skipped.
     *
     * Both halves are the decision rather than an accident. The list is an
     * exclusion, so a channel A-1 adds next month arrives and is kept — missing
     * a real enquiry is worse than showing a duplicate. And `JobSource` is free
     * text that is already dirty in their own data (`GMB /SEO` against
     * `GMB / SEO`), so the comparison strips everything that is not a letter or
     * a digit: an exact match would have let one spelling of LS Ads through.
     */
    public function test_an_unknown_channel_is_kept_and_a_dirty_spelling_is_still_skipped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Delta');
        $connection = $this->connection($t);
        $this->seedWatermark($t, $connection);

        $this->workizReturns([
            $this->payload(['UUID' => 'wz-brandnew', 'JobSource' => 'Nextdoor Neighbours']),
            $this->payload(['UUID' => 'wz-empty', 'JobSource' => '']),
            $this->payload(['UUID' => 'wz-dirty', 'JobSource' => '  ls   ads  ']),
        ]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $ids = $this->inTenant($t['org'], fn () => Lead::query()
            ->where('source', Lead::SOURCE_WORKIZ)
            ->where('remote_id', '!=', 'wz-seed')
            ->orderBy('remote_id')->pluck('remote_id')->all());

        $this->assertSame(['wz-brandnew', 'wz-empty'], $ids);
    }

    /**
     * A second run does not walk back a decision somebody made.
     *
     * `status` is EzBizi's answer about the lead and Workiz has no say in it.
     * An import that refreshed it would un-book a booked lead every night, and
     * the dispatcher who booked it would never know why.
     */
    public function test_a_later_run_refreshes_their_state_and_not_ours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Echo');
        $connection = $this->connection($t);
        $this->seedWatermark($t, $connection);

        /*
         * ONE fake with a sequence, not two calls. `Http::fake` STACKS: a
         * second call registering the same URL pattern does not replace the
         * first, so the second run would have been answered `Submitted` again
         * and this test would have passed against a command that never updated
         * anything.
         */
        Http::fake([
            'api.workiz.com/*' => Http::sequence()
                ->push(['flag' => true, 'data' => [$this->payload(['UUID' => 'wz-1', 'Status' => 'Submitted'])]])
                ->push(['flag' => true, 'data' => [$this->payload(['UUID' => 'wz-1', 'Status' => 'Converted'])]]),
        ]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $this->inTenant($t['org'], fn () => Lead::query()
            ->where('remote_id', 'wz-1')
            ->update(['status' => Lead::STATUS_BOOKED]));

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        $lead = $this->inTenant($t['org'], fn () => Lead::query()
            ->where('remote_id', 'wz-1')->firstOrFail());

        $this->assertSame(Lead::STATUS_BOOKED, $lead->status, 'ours, and not theirs to reset');
        $this->assertStringContainsString('Converted', (string) $lead->source_status, 'theirs, refreshed');

        $this->assertSame(1, $this->inTenant($t['org'], fn () => Lead::query()
            ->where('remote_id', 'wz-1')->count()), 'and not duplicated');
    }

    /** Audit 11-27: a watermark at 9:30 PM Eastern is tomorrow in UTC; ask from the day before. */
    public function test_an_evening_watermark_still_asks_for_that_evenings_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));
        $t = $this->provisionTenant('Echo');
        $connection = $this->connection($t);
        $this->seedWatermark($t, $connection);
        $this->inTenant($t['org'], fn () => Lead::query()->where('remote_id', 'wz-seed')
            ->update(['received_at' => Carbon::parse('2026-09-24 01:30:00', 'UTC')]));
        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => []])]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'start_date=2026-09-23'));
    }

    /** One unreachable branch does not stop the others. */
    public function test_a_branch_that_refuses_does_not_stop_the_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24 12:00:00', 'UTC'));

        $t = $this->provisionTenant('Foxtrot');
        $connection = $this->connection($t);
        $this->seedWatermark($t, $connection);

        Http::fake(['api.workiz.com/*' => Http::response(['message' => 'nope'], 500)]);

        $this->artisan('ezbizi:import-workiz-leads')->assertSuccessful();
    }

    private function seedWatermark(array $t, SocialConnection $connection): void
    {
        $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'franchise_id' => $t['franchise']->id,
            'connection_id' => $connection->getKey(),
            'source' => Lead::SOURCE_WORKIZ,
            'remote_id' => 'wz-seed',
            'contact_name' => 'Seed',
            'status' => Lead::STATUS_NEW,
            'received_at' => Carbon::parse('2026-09-22 12:00:00', 'UTC'),
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
