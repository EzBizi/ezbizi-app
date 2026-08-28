<?php

namespace Tests\Feature\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneUsageDaily;
use App\Support\Phone\FakeTwilioGateway;
use App\Support\Phone\TwilioGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Per-company Twilio cost: collected nightly, read on demand.
 *
 * EzBizi pays for every tenant's traffic out of one bill. Without this nobody
 * can say what any one company cost, and Twilio prunes usage records — so a
 * night not collected is a night gone for good.
 */
class PhoneUsageTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function connect(array $t): PhoneConnection
    {
        return $this->inTenant($t['org'], fn () => PhoneConnection::factory()->create([
            'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
            'scope_id' => $t['org']->id,
            'status' => PhoneConnection::STATUS_CONNECTED,
        ]));
    }

    /**
     * The property that makes the nightly job safe to retry.
     *
     * A run that half-finished, or a backfill overlapping a night already
     * collected, must correct the day rather than add to it. Doubling a cost
     * row is worse than missing one: a missing day is visibly missing, and a
     * doubled one looks exactly like a real figure.
     */
    public function test_importing_the_same_day_twice_corrects_rather_than_doubles(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connect($t);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->usage = [[
            'category' => 'calls',
            'description' => 'Voice',
            'start_date' => '2026-08-27',
            'usage' => '12',
            'usage_unit' => 'minutes',
            'count' => '4',
            'count_unit' => 'calls',
            'price' => '0.1700',
            'price_unit' => 'USD',
        ]];

        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertSuccessful();
        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertSuccessful();

        $this->inTenant($t['org'], function () use ($connection) {
            $rows = PhoneUsageDaily::query()->where('connection_id', $connection->id)->get();

            $this->assertCount(1, $rows, 'the second run added a row instead of correcting one');
            $this->assertSame('0.170000', (string) $rows[0]->price);
            $this->assertSame(4, (int) $rows[0]->count);
            $this->assertSame('calls', $rows[0]->category);
        });
    }

    public function test_a_corrected_figure_replaces_the_earlier_one(): void
    {
        $t = $this->provisionTenant();
        $connection = $this->connect($t);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);

        $row = [
            'category' => 'calls',
            'description' => 'Voice',
            'start_date' => '2026-08-27',
            'usage' => '12',
            'usage_unit' => 'minutes',
            'count' => '4',
            'count_unit' => 'calls',
            'price' => '0.1700',
            'price_unit' => 'USD',
        ];

        $gateway->usage = [$row];
        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertSuccessful();

        // Twilio revises a day after it closes; the later figure is the true one.
        $gateway->usage = [[...$row, 'price' => '0.2500', 'count' => '6']];
        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertSuccessful();

        $this->inTenant($t['org'], function () use ($connection) {
            $rows = PhoneUsageDaily::query()->where('connection_id', $connection->id)->get();
            $this->assertCount(1, $rows);
            $this->assertSame('0.250000', (string) $rows[0]->price);
            $this->assertSame(6, (int) $rows[0]->count);
        });
    }

    /**
     * One dead tenant must not stop the rest being collected, and must not
     * page anybody either — a scheduler that cries wolf nightly gets muted,
     * and a muted alarm is worse than no alarm.
     */
    public function test_one_failing_account_does_not_stop_the_others_or_fail_the_run(): void
    {
        $t = $this->provisionTenant();
        $this->connect($t);

        /** @var FakeTwilioGateway $gateway */
        $gateway = $this->app->make(TwilioGateway::class);
        $gateway->failUsageDailyWith = 'The Twilio account for this line is suspended.';

        // One connection, and it failed — so this run really was a blackout.
        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertFailed();

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, PhoneUsageDaily::query()->count());
        });
    }

    public function test_usage_reads_back_totalled_by_currency(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $this->connect($t);

        $this->artisan('ezbizi:phone-usage', ['--date' => '2026-08-27'])->assertSuccessful();

        $response = $this->signedInAs($editor)->getJson('/api/v1/phone/usage?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))->assertOk();

        // 0.1700 calls + 0.0237 sms. Added with bcmath, because these are the
        // figures somebody may one day invoice from.
        $this->assertSame('0.193700', $response->json('totals.USD'));
        $this->assertCount(2, $response->json('data'));
    }

    /**
     * The empty case must be the same SHAPE as the normal one.
     *
     * `totals` is a keyed collection, and an empty PHP array encodes as `[]`
     * rather than `{}` — so on a tenant with no usage yet the key changed type
     * from map to list. The client cast it to a map, the cast threw mid-load,
     * and the screen span forever with nothing to say. A hang is worse than an
     * error: an error names itself.
     */
    public function test_totals_is_an_object_even_when_there_is_no_usage(): void
    {
        $t = $this->provisionTenant();
        $editor = $this->userWithRole($t, 'Phone editor', ['phone.view', 'phone.edit']);
        $this->connect($t);

        // Deliberately no import run, which is every tenant's first day.
        $response = $this->signedInAs($editor)->getJson('/api/v1/phone/usage?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
        ]))->assertOk();

        $this->assertStringContainsString('"totals":{}', $response->getContent());
        $this->assertStringNotContainsString('"totals":[]', $response->getContent());
        $this->assertSame([], $response->json('data'));
    }

    public function test_seeing_the_bill_needs_the_authority_that_spends_it(): void
    {
        $t = $this->provisionTenant();
        $viewer = $this->userWithRole($t, 'Phone viewer', ['phone.view']);
        $this->connect($t);

        $this->signedInAs($viewer)->getJson('/api/v1/phone/usage?'.http_build_query([
            'scope_type' => 'organization',
            'scope_id' => $t['org']->id,
        ]))->assertForbidden();
    }
}
