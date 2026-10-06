<?php

namespace Tests\Feature\Workiz;

use App\Models\Customer;
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
 * A customer's Workiz client notes, read live (Jose via Customers,
 * 2026-10-06). Shapes as the v2 spec documents them: a client is CL-…, a
 * note {id, note, pin, techName, createdAt}.
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

    public function test_it_reads_the_notes_live_newest_first(): void
    {
        $this->client(3909, 'CL-Bk90obeWmD8zme13');
        Http::fake(['api.workiz.com/crm/api/v2/clients/CL-Bk90obeWmD8zme13/notes*' => Http::response(['hasMore' => false, 'data' => [
            ['id' => 'NT-1', 'note' => 'Dog in the yard', 'pin' => false, 'techName' => 'Ana Ruiz', 'createdAt' => '2026-03-01 09:00:00'],
            ['id' => 'NT-2', 'note' => 'Gate code 4411', 'pin' => true, 'techName' => 'Bo Lin', 'createdAt' => '2026-09-01 09:00:00'],
        ]])]);

        $this->signedInAs($this->tenant['admin'])->getJson('/api/v1/customers/'.$this->customer->id.'/workiz-notes')
            ->assertOk()
            ->assertExactJson(['data' => [[
                'client_id' => 3909,
                'franchise' => ['id' => $this->tenant['franchise']->id, 'name' => $this->tenant['franchise']->name],
                'notes' => [
                    ['text' => 'Gate code 4411', 'created_at' => '2026-09-01 09:00:00', 'author' => 'Bo Lin'],
                    ['text' => 'Dog in the yard', 'created_at' => '2026-03-01 09:00:00', 'author' => 'Ana Ruiz'],
                ],
            ]]]);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer v2key_CT'));
    }

    /** One client Workiz cannot answer for, or not matched yet, says why; the page is not a 500. */
    public function test_a_client_that_cannot_be_read_says_why_and_the_others_still_answer(): void
    {
        $this->client(3909, 'CL-Bk90obeWmD8zme13');
        $this->client(4100, null);
        Http::fake(['api.workiz.com/*' => Http::response(['message' => 'Too Many Requests', 'statusCode' => 429], 429)]);

        $rows = collect($this->signedInAs($this->tenant['admin'])->getJson('/api/v1/customers/'.$this->customer->id.'/workiz-notes')
            ->assertOk()->json('data'))->keyBy('client_id');

        // Only Workiz's own refusal is worth trying again.
        $this->assertSame(['Too Many Requests', true], [$rows[3909]['error'], $rows[3909]['retry'] ?? null]);
        $this->assertSame('Not matched to Workiz yet.', $rows[4100]['error']);
        $this->assertArrayNotHasKey('retry', $rows[4100]);
        Http::assertSentCount(1);
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
