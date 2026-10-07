<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
use App\Models\Job;
use App\Models\SocialConnection;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The notes on a customer's Workiz jobs (Customers, 2026-10-07), and the
 * client-id sweep. A job note is {id, techName, note, timestamp}, measured
 * on CT 200022994; a client is CL-….
 */
class CustomerWorkizNotesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('Alpha');
        $this->customer = $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $this->tenant['franchise']->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $this->tenant['franchise']->id,
                'display_name' => 'Connecticut',
            ]);
            $c->connect(['api_token' => 'v2key_CT', 'refresh_token' => 'v2key_CT', 'api_version' => 'v2']);
            $c->save();

            return Customer::create(['owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C-1', 'name' => 'Pat Rivera', 'is_active' => true]);
        });
    }

    private function client(int $number, ?string $v2Id): WorkizClient
    {
        return $this->inTenant($this->tenant['org'], fn () => WorkizClient::query()->forceCreate([
            'organization_id' => $this->tenant['org']->id, 'franchise_id' => $this->tenant['franchise']->id,
            'client_id' => $number, 'v2_id' => $v2Id, 'name' => 'Pat Rivera', 'customer_id' => $this->customer->id, 'synced_at' => now(), 'last_seen_at' => now(),
        ]));
    }

    /** A job in the fixture's shape, landed as the sync lands it, and linked to an EzBizi job numbered $number. */
    private function job(string $uuid, int $serial, int $client, array $notes, string $number): Job
    {
        return $this->inTenant($this->tenant['org'], function () use ($uuid, $serial, $client, $notes, $number) {
            $fixture = require base_path('tests/Fixtures/workiz/v2_job.php');
            WorkizJob::land(SocialConnection::query()->sole(), WorkizV2::job(array_replace($fixture, [
                'id' => 'JOB-'.$uuid, 'uuid' => $uuid, 'serialId' => $serial, 'notes' => $notes,
                'clientInfo' => array_replace($fixture['clientInfo'], ['serialId' => $client]),
            ])));
            $job = Job::create(['franchise_id' => $this->tenant['franchise']->id, 'customer_id' => $this->customer->id, 'number' => $number, 'title' => 'Sweep', 'status' => 'scheduled']);
            WorkizJob::query()->where('remote_id', $uuid)->update(['job_id' => $job->id]);

            return $job;
        });
    }

    /**
     * Customers, 2026-10-07: A-1's notes are on jobs (CT 200022994 holds one;
     * client notes were empty on every client read). They arrive with each
     * job the sync reads, so the page asks Workiz nothing.
     */
    public function test_it_lists_the_notes_on_the_customers_jobs_newest_first(): void
    {
        $this->client(1008, 'CL-xzA4NAKqzXW76XQp');
        $older = $this->job('AAA111', 11008, 1008, [['id' => 'JNT-1', 'techName' => 'Ana Ruiz', 'note' => 'Dog in the yard', 'timestamp' => '2026-03-01 09:00:00']], 'J-1');
        $newer = $this->job('BBB222', 11009, 1008, [['id' => 'JNT-2', 'techName' => 'Bo Lin', 'note' => 'Gate code 4411', 'timestamp' => '2026-09-01 09:00:00']], 'J-2');
        $this->job('CCC333', 11010, 2000, [['id' => 'JNT-3', 'techName' => 'Cy Ode', 'note' => 'Another client', 'timestamp' => '2026-09-02 09:00:00']], 'J-3');
        Http::fake();

        $this->signedInAs($this->tenant['admin'])->getJson('/api/v1/customers/'.$this->customer->id.'/workiz-notes')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'client_id' => 1008,
                'franchise' => ['id' => $this->tenant['franchise']->id, 'name' => $this->tenant['franchise']->name],
                'notes' => [
                    ['text' => 'Gate code 4411', 'created_at' => '2026-09-01 09:00:00', 'author' => 'Bo Lin', 'job' => ['id' => $newer->id, 'number' => 'J-2', 'workiz_number' => 11009]],
                    ['text' => 'Dog in the yard', 'created_at' => '2026-03-01 09:00:00', 'author' => 'Ana Ruiz', 'job' => ['id' => $older->id, 'number' => 'J-1', 'workiz_number' => 11008]],
                ],
            ]]]);
        Http::assertNothingSent();
    }

    /** Only v2 carries job notes: a branch still on v1 says why rather than claiming there are none. */
    public function test_a_branch_not_on_v2_says_why(): void
    {
        $this->client(1008, 'CL-xzA4NAKqzXW76XQp');
        $this->job('AAA111', 11008, 1008, [['id' => 'JNT-1', 'techName' => 'Ana Ruiz', 'note' => 'Dog in the yard', 'timestamp' => '2026-03-01 09:00:00']], 'J-1');
        $this->inTenant($this->tenant['org'], function () {
            $c = SocialConnection::query()->sole();
            $c->connect(['api_token' => 'v1token_CT', 'auth_secret' => 'v1secret_CT']);
            $c->save();
        });

        $row = $this->signedInAs($this->tenant['admin'])->getJson('/api/v1/customers/'.$this->customer->id.'/workiz-notes')->assertOk()->json('data.0');

        $this->assertSame(['This branch is not on its Workiz v2 key.', []], [$row['error'] ?? null, $row['notes']]);
    }

    public function test_it_is_behind_viewing_the_customer(): void
    {
        $this->client(3909, 'CL-Bk90obeWmD8zme13');
        Http::fake();
        $nobody = $this->userWithRole($this->tenant, 'No customers');

        $this->signedInAs($nobody)->getJson('/api/v1/customers/'.$this->customer->id.'/workiz-notes')->assertForbidden();
        Http::assertNothingSent();
    }

    /**
     * v2 stops a client list at row 10,000 (New Jersey, 2026-10-06), so --full
     * walks one year of clients at a time, by when Workiz created them.
     */
    public function test_a_full_sweep_walks_the_clients_year_by_year(): void
    {
        $this->client(3909, null);
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(['hasMore' => false, 'data' => ($q['date'] ?? '') === '2025-01-01_2025-12-31'
                ? [['id' => 'CL-new3909', 'serialId' => 3909]] : []]);
        });

        $this->artisan('ezbizi:sync-workiz-client-ids', ['--full' => true])->assertSuccessful();

        $this->inTenant($this->tenant['org'], fn () => $this->assertSame('CL-new3909', WorkizClient::query()->sole()->v2_id));
        Http::assertSentCount(now()->year - 2013);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return ($q['dateProperty'] ?? null) === 'created' && ($q['dateOperator'] ?? null) === 'between' && $q['date'] === '2014-01-01_2014-12-31';
        });
    }

    /**
     * v2's list leaves out some clients it holds (Connecticut 5839,
     * 2026-10-06): such a client takes the id its mirrored job carries, and a
     * client with no job stays unmatched.
     */
    public function test_a_client_the_list_leaves_out_takes_the_id_its_job_carries(): void
    {
        $this->client(1008, null);
        $this->client(3909, null);
        $this->inTenant($this->tenant['org'], fn () => WorkizJob::land(SocialConnection::query()->sole(), WorkizV2::job(require base_path('tests/Fixtures/workiz/v2_job.php'))));
        Http::fake(['api.workiz.com/crm/api/v2/clients*' => Http::response(['hasMore' => false, 'data' => []])]);

        $this->artisan('ezbizi:sync-workiz-client-ids')->assertSuccessful();

        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(['1008' => 'CL-xzA4NAKqzXW76XQp', '3909' => null],
            WorkizClient::query()->orderBy('client_id')->pluck('v2_id', 'client_id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all()));
    }

    /** v2 lists clients newest number first: a run stops at the first page with nothing new; --full reads on. */
    public function test_the_sweep_gives_each_client_its_v2_id(): void
    {
        $this->client(3909, null);
        $this->client(3580, null);
        Http::fake(['api.workiz.com/crm/api/v2/clients*' => Http::sequence()
            ->push(['hasMore' => true, 'data' => [['id' => 'CL-new3909', 'serialId' => 3909], ['id' => 'CL-new3580', 'serialId' => 3580]]])
            ->push(['hasMore' => false, 'data' => [['id' => 'CL-old1001', 'serialId' => 1001]]])
            ->push(['hasMore' => true, 'data' => [['id' => 'CL-new3909', 'serialId' => 3909], ['id' => 'CL-new3580', 'serialId' => 3580]]])]);

        $this->artisan('ezbizi:sync-workiz-client-ids')->assertSuccessful();
        $this->artisan('ezbizi:sync-workiz-client-ids')->assertSuccessful();

        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(['3580' => 'CL-new3580', '3909' => 'CL-new3909'],
            WorkizClient::query()->orderBy('client_id')->pluck('v2_id', 'client_id')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all()));
        Http::assertSentCount(3); // two pages, then one page with nothing new
    }
}
