<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The v2 records beyond jobs (Jose, 2026-10-06: "take advantage of everything
 * v2 has to offer"). Every record faked here has the shape Connecticut's key
 * answered with that day.
 */
class SyncWorkizRecordsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const RECORDS = [
        'invoices' => ['id' => 'IV-1', 'uuid' => 'BYABUO', 'serialId' => 200020306, 'name' => '', 'notes' => 'Gate code 4411', 'jobId' => 'JOB-1',
            'subTotal' => 299, 'tipAmount' => 0, 'totalPrice' => 329.12, 'amountDue' => 0, 'updated' => '2026-10-05 22:41:13', 'created' => '2026-10-05 18:38:44'],
        'payments' => ['invoiceId' => 'IV-1', 'jobId' => 'JOB-1', 'id' => 'PAY-1', 'date' => '2026-10-05 18:41:13', 'amount' => 329.12, 'tipAmount' => 0,
            'type' => 'charge', 'status' => 'succeeded', 'estimateId' => null, 'collectedById' => 'USR-1', 'netAmount' => 320.3, 'clientId' => 'CL-1', 'confirmationCode' => 'ch_9'],
        'estimates' => ['id' => 'ES-1', 'status' => 'Pending', 'serialId' => '200023384-2', 'name' => '', 'description' => 'Rebuild the crown', 'notes' => 'Dog in yard',
            'clientId' => 'CL-1', 'jobId' => 'JOB-1', 'subTotal' => 3100, 'total' => 3296.85, 'estimateDate' => '2026-10-05 14:00:00', 'updated' => '2026-10-06 01:24:52', 'created' => '2026-10-05 21:23:56'],
        'timeoff' => ['id' => 'TO-1', 'techId' => 'USR-2', 'startDate' => '2026-10-12 00:00:00', 'endDate' => '2026-10-14 23:59:00', 'status' => 'approved',
            'reason' => 'Medical', 'details' => 'Surgery', 'isAllDay' => true, 'updatedAt' => '2026-10-01 10:00:00', 'createdAt' => '2026-10-01 10:00:00'],
        'event' => ['id' => 'EV-1', 'status' => 'active', 'title' => 'Truck service', 'description' => 'Brakes', 'start' => '2026-10-09 08:00:00', 'end' => '2026-10-09 12:00:00',
            'isAllDay' => false, 'techIds' => ['USR-2'], 'isDeleted' => true, 'updatedAt' => '2026-10-02 10:00:00', 'createdAt' => '2026-10-02 10:00:00'],
        'timeSheet' => ['id' => 'TS-1', 'techId' => 'USR-3', 'techName' => 'Pat Doe', 'startDate' => '2026-10-05 09:00:00', 'endDate' => '2026-10-05 11:00:00',
            'notes' => 'Ladder', 'jobId' => 'JOB-1', 'laborCost' => 40, 'shiftLength' => '02:00'],
        // The account lists, in the shape measured on NY 2026-10-27.
        'jobTypes' => ['id' => 'JT-1', 'name' => 'Chimney Sweep', 'avgDuration' => 60, 'displayOrder' => 1, 'disabled' => false],
        'adGroups' => ['id' => 'AG-1', 'name' => 'Google LSA', 'displayOrder' => 2, 'description' => '', 'disabled' => false],
        'tags' => ['id' => 'TG-1', 'name' => 'DEPOSIT RECIEVED', 'color' => '#00aa00', 'type' => 'job'],
        'jobSubStatus' => ['id' => 'JSS-1', 'name' => 'Estimate Declined', 'parentName' => 'Canceled', 'color' => '#ff0000'],
        'customfields' => ['id' => 'CF-1', 'entityType' => 'JOB', 'name' => 'Pricing', 'type' => 'Drop Down', 'options' => ['$99 - Level 1 Inspection'], 'position' => 4],
        'items' => ['id' => 'ITM-1', 'serialId' => 1173, 'name' => 'Premium Gas System Replacement', 'cost' => 0, 'price' => 6699, 'type' => 'product',
            'active' => true, 'updated' => '2026-09-29 19:00:18'],
    ];

    /** @return array{0: array, 1: SocialConnection} */
    private function branch(string $version = 'v2', string $name = 'Connecticut'): array
    {
        $tenant = $this->provisionTenant('Alpha');

        return [$tenant, $this->connection($tenant, $version, $name)];
    }

    private function connection(array $tenant, string $version, string $name): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $version, $name) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $tenant['franchise']->id.$name,
                'display_name' => $name,
            ]);
            $c->connect($version === 'v2'
                ? ['api_token' => 'v2key_CT', 'refresh_token' => 'v2key_CT', 'api_version' => 'v2']
                : ['api_token' => 'api_CT', 'refresh_token' => 'api_CT', 'auth_secret' => 'sec_CT']);
            $c->save();

            return $c;
        });
    }

    /** One fake for every type: the given records, or the default one of each. */
    private function fake(array $records = []): void
    {
        $fakes = [];

        foreach (array_replace(array_map(fn ($r) => [$r], self::RECORDS), $records) as $path => $rows) {
            $fakes['api.workiz.com/crm/api/v2/'.$path.'*'] = is_array($rows)
                ? Http::response(['pageSize' => 300, 'page' => 1, 'totalResults' => count($rows), 'hasMore' => false, 'data' => $rows])
                : $rows;
        }

        Http::fake($fakes);
    }

    private function record(array $tenant, string $type): ?WorkizRecord
    {
        return $this->inTenant($tenant['org'], fn () => WorkizRecord::query()->where('type', $type)->first());
    }

    /** 11-03: the account lists are read in the first ten minutes of the hour only, to stay under Workiz's rate limit. */
    public function test_the_account_lists_are_read_once_an_hour(): void
    {
        $this->branch();
        $this->fake();
        $lists = fn () => Http::recorded(fn ($r) => preg_match('#/(jobTypes|adgroups|adGroups|tags|jobSubStatus|customfields|customFields)#', $r->url()))->count();

        $this->travelTo(now()->setTime(9, 25));
        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();
        $this->assertSame(0, $lists(), 'not at :25');

        $this->travelTo(now()->setTime(10, 5));
        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();
        $this->assertGreaterThan(0, $lists(), 'at :05');
    }

    /** Jose 11-04: where a full read starts is the branch's setting, not a date in code. */
    public function test_a_full_read_starts_at_the_branchs_import_date(): void
    {
        [$tenant] = $this->branch();
        $this->inTenant($tenant['org'], fn () => app(\App\Support\Settings\SettingsResolver::class)->set('workiz.import_since', $tenant['org'], '2025-03-01'));

        $this->assertSame('2025-03-01', $this->inTenant($tenant['org'], fn () => \App\Support\Workiz\WorkizRecords::cursor(null, $tenant['franchise'])));
        $this->assertSame('2023-01-01', \App\Support\Workiz\WorkizRecords::cursor(null), 'no branch: the default');
    }

    /** Jose 11-10: a tag Workiz renames reaches every mirrored job, though Workiz leaves the jobs' modified time alone. */
    public function test_a_renamed_tag_is_renamed_on_every_mirrored_job(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->fake();
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['tag']])->assertSuccessful();
        $job = $this->inTenant($tenant['org'], fn () => \App\Models\WorkizJob::query()->create(['franchise_id' => $tenant['franchise']->id,
            'connection_id' => $connection->id, 'remote_id' => 'J1', 'status' => 'Done', 'payload' => ['Tags' => ['DEPOSIT RECIEVED', 'VIP']], 'synced_at' => now()]));

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->fake(['tags' => [['id' => 'TG-1', 'name' => 'DEPOSIT RECEIVED', 'color' => '#00aa00', 'type' => 'job']]]);
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['tag']])->assertSuccessful();

        $this->assertSame(['DEPOSIT RECEIVED', 'VIP'], $this->inTenant($tenant['org'], fn () => $job->fresh()->payload['Tags']));
    }

    public function test_each_type_lands_with_the_columns_readers_filter_on_and_without_the_free_text(): void
    {
        [$tenant] = $this->branch();
        $this->fake();
        $this->travelTo(now()->setTime(9, 5));

        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();

        $invoice = $this->record($tenant, 'invoice');
        $this->assertSame(['JOB-1', '329.12', '0.00', '2026-10-05 18:38:44', '2026-10-05 22:41:13'],
            [$invoice->job_remote_id, $invoice->amount, $invoice->amount_due, (string) $invoice->starts_at, (string) $invoice->remote_updated_at]);
        $payment = $this->record($tenant, 'payment');
        $this->assertSame(['JOB-1', 'USR-1', '329.12', 'succeeded', '2026-10-05 18:41:13'],
            [$payment->job_remote_id, $payment->user_remote_id, $payment->amount, $payment->status, (string) $payment->starts_at]);
        $estimate = $this->record($tenant, 'estimate');
        $this->assertSame(['3296.85', 'Pending', '2026-10-05 14:00:00'], [$estimate->amount, $estimate->status, (string) $estimate->starts_at]);
        $off = $this->record($tenant, 'time_off');
        $this->assertSame(['USR-2', '2026-10-12 00:00:00', '2026-10-14 23:59:00'], [$off->user_remote_id, (string) $off->starts_at, (string) $off->ends_at]);
        $event = $this->record($tenant, 'event');
        $this->assertSame(['deleted', ['USR-2'], 'Truck service'], [$event->status, $event->payload['techIds'], $event->payload['title']]);
        $sheet = $this->record($tenant, 'timesheet');
        $this->assertSame(['USR-3', 'JOB-1', '40.00'], [$sheet->user_remote_id, $sheet->job_remote_id, $sheet->amount]);
        $this->assertSame('Pricing', $this->record($tenant, 'custom_field')->payload['name']);
        $this->assertSame('6699.00', $this->record($tenant, 'item')->amount);
        $this->assertSame(['Chimney Sweep', 'Google LSA', 'DEPOSIT RECIEVED', 'Canceled'], [$this->record($tenant, 'job_type')->payload['name'],
            $this->record($tenant, 'ad_group')->payload['name'], $this->record($tenant, 'tag')->payload['name'], $this->record($tenant, 'sub_status')->payload['parentName']]);

        foreach (['invoice' => 'notes', 'payment' => 'confirmationCode', 'estimate' => 'description', 'time_off' => 'reason', 'event' => 'description', 'timesheet' => 'techName'] as $type => $field) {
            $this->assertArrayNotHasKey($field, $this->record($tenant, $type)->payload, $type.' keeps no '.$field);
        }
    }

    public function test_a_record_finds_the_ezbizi_job_its_workiz_job_became(): void
    {
        [$tenant, $connection] = $this->branch();
        $this->fake();

        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['payment']])->assertSuccessful();
        $this->assertNull($this->record($tenant, 'payment')->job_id, 'its job is not mirrored yet');

        $job = $this->inTenant($tenant['org'], function () use ($tenant, $connection) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            $job = Job::create(['franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep', 'status' => 'Submitted', 'currency' => 'USD']);
            WorkizJob::land($connection, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1'])));
            WorkizJob::query()->update(['job_id' => $job->id]);

            return $job;
        });

        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['payment']])->assertSuccessful();

        $this->assertSame($job->id, $this->record($tenant, 'payment')->job_id);
    }

    /**
     * Automations, 2026-10-21: a payment new to the mirror raises
     * job.payment_received on its EzBizi job, once. One dated weeks back is
     * history arriving and raises nothing.
     */
    public function test_a_new_payment_raises_payment_received_once(): void
    {
        [$tenant, $connection] = $this->branch();
        $job = $this->inTenant($tenant['org'], function () use ($connection) {
            $customer = Customer::create(['owning_franchise_id' => $connection->scope_id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
            $job = Job::create(['franchise_id' => $connection->scope_id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep', 'status' => 'Submitted', 'currency' => 'USD']);
            WorkizJob::land($connection, WorkizV2::job(array_replace(require base_path('tests/Fixtures/workiz/v2_job.php'), ['id' => 'JOB-1', 'totalPrice' => 500, 'status' => 'done pending approval'])));
            WorkizJob::query()->update(['job_id' => $job->id]);

            return $job;
        });
        $recent = array_replace(self::RECORDS['payments'], ['id' => 'PAY-NEW', 'amount' => 200, 'date' => now()->subHour()->format('Y-m-d H:i:s')]);
        $old = array_replace(self::RECORDS['payments'], ['id' => 'PAY-OLD', 'amount' => 100, 'date' => now()->subMonth()->format('Y-m-d H:i:s')]);
        // Jobs, 2026-10-21: succeeded only, and the total is charges less refunds.
        $refund = array_replace(self::RECORDS['payments'], ['id' => 'PAY-REF', 'amount' => 50, 'type' => 'refund', 'date' => now()->subMonth()->format('Y-m-d H:i:s')]);
        $failed = array_replace(self::RECORDS['payments'], ['id' => 'PAY-FAIL', 'amount' => 75, 'status' => 'failed', 'date' => now()->subHour()->format('Y-m-d H:i:s')]);
        $this->fake(['payments' => [$recent, $old, $refund, $failed]]);

        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['payment']])->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['payment']])->assertSuccessful();

        $events = $this->inTenant($tenant['org'], fn () => \App\Models\AutomationEvent::query()->where('event_key', 'job.payment_received')->get());
        $this->assertCount(1, $events);
        $this->assertSame($job->id, $events[0]->subject_id);
        $this->assertEquals(['amount' => 200, 'paid_total' => 250, 'job_total' => 500, 'fully_paid' => false, 'status' => 'done pending approval',
            'amount_minor' => 20000, 'kind' => 'charge', 'paid_on' => now()->subHour()->toDateString(), 'collected_by' => null, 'paid_total_minor' => 25000,
        ], $events[0]->payload['after']);
    }

    /** A second run asks only for what changed since the newest it holds, less two days; --full asks from 2023. */
    public function test_a_second_run_reads_only_what_changed(): void
    {
        $this->branch();
        $this->fake();
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['invoice']])->assertSuccessful();

        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['invoice']])->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['invoice'], '--full' => true])->assertSuccessful();

        $query = function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return $q;
        };
        $this->assertSame(['2023-01-01', '2026-10-03', '2023-01-01'], collect(Http::recorded())->map(fn ($pair) => $query($pair[0])['date'] ?? null)->all());
        Http::assertSent(fn (Request $r) => ($query($r)['dateProperty'] ?? null) === 'updated' && $r->hasHeader('Authorization', 'Bearer v2key_CT'));
    }

    public function test_landing_again_updates_the_row_and_a_reconnect_does_not_double_it(): void
    {
        [$tenant] = $this->branch();
        // ONE fake: a second Http::fake stacks behind the first and never answers.
        Http::fake(['api.workiz.com/crm/api/v2/invoices*' => Http::sequence()
            ->push(['hasMore' => false, 'data' => [self::RECORDS['invoices']]])
            ->push(['hasMore' => false, 'data' => [['amountDue' => 12.5] + self::RECORDS['invoices']]])]);
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['invoice']])->assertSuccessful();

        // Disconnecting deletes the connection; reconnecting mints a new id.
        $this->inTenant($tenant['org'], fn () => SocialConnection::query()->delete());
        $this->connection($tenant, 'v2', 'Connecticut');
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['invoice']])->assertSuccessful();

        $this->inTenant($tenant['org'], function () {
            $this->assertSame(1, WorkizRecord::query()->count());
            $this->assertSame('12.50', WorkizRecord::query()->sole()->amount_due);
        });
    }

    public function test_a_refused_type_is_named_and_the_others_still_land(): void
    {
        [$tenant] = $this->branch();
        Log::spy();
        $this->fake(['invoices' => Http::response(['message' => 'Missing permission: invoices', 'statusCode' => 403], 403)]);

        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();

        $this->assertNull($this->record($tenant, 'invoice'));
        $this->assertNotNull($this->record($tenant, 'payment'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'Connecticut (invoice)') && str_contains($m, 'Missing permission') && ! str_contains($m, 'v2key_CT'));
    }

    /** The limit is the key's: a 429 ends that branch's run, it does not walk on into seven more refusals. */
    public function test_a_429_stops_the_branch_for_this_run(): void
    {
        $this->branch();
        $this->fake(['invoices' => Http::response(['message' => 'Too Many Requests', 'statusCode' => 429], 429)]);

        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();

        Http::assertSentCount(1);
    }

    /** Timesheets come newest first with no date filter: the run stops at the newest one it already holds. */
    public function test_timesheets_stop_at_the_cursor(): void
    {
        [$tenant] = $this->branch();
        $newer = ['id' => 'TS-2', 'startDate' => '2026-10-06 09:00:00'] + self::RECORDS['timeSheet'];
        $older = ['id' => 'TS-0', 'startDate' => '2026-09-01 09:00:00'] + self::RECORDS['timeSheet'];
        Http::fake(['api.workiz.com/crm/api/v2/timeSheet*' => Http::sequence()
            ->push(['hasMore' => false, 'data' => [self::RECORDS['timeSheet']]])
            ->push(['hasMore' => true, 'data' => [$newer, $older]])]);
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['timesheet']])->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-records', ['--type' => ['timesheet']])->assertSuccessful();

        $this->inTenant($tenant['org'], fn () => $this->assertSame(['TS-1', 'TS-2'],
            WorkizRecord::query()->orderBy('remote_id')->pluck('remote_id')->all()));
        Http::assertSentCount(2);
    }

    public function test_a_v1_branch_is_not_read(): void
    {
        $this->branch('v1');
        $this->fake();

        $this->artisan('ezbizi:sync-workiz-records')->assertSuccessful();

        Http::assertNothingSent();
    }
}
