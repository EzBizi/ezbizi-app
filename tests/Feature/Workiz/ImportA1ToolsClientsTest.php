<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ImportA1ToolsClients;
use App\Console\Commands\ProjectWorkizRecords;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Models\WorkizJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `customers:import-a1-tools` — docs/A1-TOOLS-CLIENTS-JOBS-MIGRATION.md, the
 * clients importer's section. Every fixture is built from the bundle format
 * written there, so a pass is a claim about what the A1 Tools export writes.
 */
class ImportA1ToolsClientsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private string $file;

    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->t = $this->provisionTenant('Alpha');

        $this->inTenant($this->t['org'], function () {
            $home = Company::create(['organization_id' => $this->t['org']->id, 'name' => 'A-1 Chimney']);

            foreach (['nj' => 'New Jersey', 'ct' => 'Connecticut'] as $code => $name) {
                $this->ids[$code] = Franchise::create([
                    'organization_id' => $this->t['org']->id, 'company_id' => $home->id, 'name' => $name,
                ])->id;
            }
        });

        $this->file = sys_get_temp_dir().'/a1-clients-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    // ── the bundle ───────────────────────────────────────────────

    private function client(array $over = []): array
    {
        return array_replace([
            'a1_id' => 18234,
            'franchise' => 'NJ',
            'workiz' => [['franchise' => 'NJ', 'client_id' => 4512]],
            'first_name' => 'Ann',
            'last_name' => 'Lee',
            'display_name' => null,
            'company_name' => null,
            'email' => 'ann.lee@example.test',
            'phone' => '(201) 555-0100',
            'phone_secondary' => null,
            'address_line1' => '12 Oak St',
            'address_line2' => null,
            'city' => 'Hoboken',
            'state' => 'NJ',
            'zip' => '07030',
            'notes' => null,
            'client_since' => '2025-10-14',
        ], $over);
    }

    private function bundle(array $clients, array $extra = []): void
    {
        file_put_contents($this->file, json_encode(array_replace([
            'format' => ImportA1ToolsClients::FORMAT,
            'exported_at' => '2026-09-30T14:00:00Z',
            'organization' => $this->t['org']->name,
            'clients' => $clients,
        ], $extra)));
    }

    private function import(array $options = [])
    {
        return $this->artisan('customers:import-a1-tools', ['file' => $this->file, '--wait' => 0] + $options);
    }

    private function customers(): Collection
    {
        return $this->inTenant($this->t['org'], fn () => Customer::query()->orderBy('number')->get());
    }

    private function links(): Collection
    {
        return $this->inTenant($this->t['org'], fn () => WorkizClient::query()->get());
    }

    /** Every manifest line the runs wrote, decoded. */
    private function manifest(): array
    {
        $lines = [];

        foreach (Storage::disk('local')->allFiles('a1-imports') as $path) {
            foreach (array_filter(explode("\n", Storage::disk('local')->get($path))) as $line) {
                $lines[] = json_decode($line, true);
            }
        }

        return $lines;
    }

    private function existingCustomer(array $attributes, ?int $workizClientId = null): Customer
    {
        return $this->inTenant($this->t['org'], function () use ($attributes, $workizClientId) {
            $customer = Customer::create($attributes + [
                'owning_franchise_id' => $this->ids['nj'],
                'number' => 'C-9'.random_int(1000, 9999),
                'is_active' => true,
            ]);

            if ($workizClientId !== null) {
                WorkizClient::create([
                    'franchise_id' => $this->ids['nj'],
                    'client_id' => $workizClientId,
                    'customer_id' => $customer->id,
                    'synced_at' => now(),
                ]);
            }

            return $customer;
        });
    }

    // ── what it creates ──────────────────────────────────────────

    public function test_an_a1_only_client_becomes_a_customer_with_its_workiz_key_registered(): void
    {
        $this->bundle([$this->client([
            'company_name' => 'Lee Holdings',
            'phone_secondary' => '201-555-0101',
            'notes' => 'Gate code 4411',
        ])]);

        $this->import()->assertSuccessful();

        $customer = $this->customers()->sole();
        $this->assertSame('Ann Lee', $customer->name);
        $this->assertSame('ann.lee@example.test', $customer->email);
        $this->assertSame('+12015550100', $customer->phone);
        $this->assertSame('12 Oak St', $customer->address_line1);
        $this->assertSame('Hoboken', $customer->city);
        $this->assertSame('NJ', $customer->state);
        $this->assertSame('07030', $customer->postal_code);
        $this->assertSame($this->ids['nj'], $customer->owning_franchise_id);
        $this->assertTrue($customer->is_active);
        $this->assertStringStartsWith('C-', $customer->number);
        $this->assertSame(implode("\n", [
            'From A1 Tools, Workiz client #4512 (New Jersey), client since 2025-10-14.',
            'Company: Lee Holdings',
            'Second phone: 201-555-0101',
            'Gate code 4411',
        ]), $customer->notes);

        // Without this row, the client's next Workiz job would mint a second customer.
        $link = $this->links()->sole();
        $this->assertSame($customer->id, $link->customer_id);
        $this->assertSame($this->ids['nj'], $link->franchise_id);
        $this->assertSame(4512, (int) $link->client_id);
    }

    public function test_a_client_with_no_name_is_named_from_what_a1_holds(): void
    {
        $this->bundle([
            $this->client(['a1_id' => 1, 'workiz' => [], 'phone' => null, 'first_name' => null, 'last_name' => null, 'display_name' => 'The Lees']),
            $this->client(['a1_id' => 2, 'workiz' => [], 'phone' => null, 'first_name' => null, 'last_name' => null, 'company_name' => 'Oak Realty']),
            $this->client(['a1_id' => 3, 'workiz' => [], 'phone' => null, 'first_name' => null, 'last_name' => null]),
        ]);

        $this->import()->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['The Lees', 'Oak Realty', 'A1 Tools client 3'],
            $this->customers()->pluck('name')->all(),
        );
    }

    // ── what it never touches ────────────────────────────────────

    public function test_a_client_whose_workiz_key_ezbizi_already_holds_is_not_touched(): void
    {
        $existing = $this->existingCustomer(['name' => 'Ann Lee-Park', 'email' => 'kept@example.test'], workizClientId: 4512);
        $before = $existing->updated_at->toDateTimeString();

        $this->travel(5)->minutes();
        $this->bundle([$this->client(['email' => 'a1@example.test'])]);

        $this->import()->assertSuccessful();

        $this->assertSame([$existing->id], $this->customers()->pluck('id')->all());
        $fresh = $this->customers()->sole();
        $this->assertSame('Ann Lee-Park', $fresh->name);
        $this->assertSame('kept@example.test', $fresh->email);
        $this->assertSame($before, $fresh->updated_at->toDateTimeString());
        $this->assertCount(1, $this->links());

        // The billing import will look A1's client up by its id: matched rows are in the manifest too.
        $this->assertSame(
            [['a1_id' => 18234, 'status' => 'workiz', 'customer_id' => $existing->id]],
            $this->manifest(),
        );
    }

    public function test_a_phone_matches_only_with_the_same_name(): void
    {
        $existing = $this->existingCustomer(['name' => 'Ann Lee', 'phone' => '+12015550100']);

        $this->bundle([
            // The same person, entered again under another Workiz number.
            $this->client(['a1_id' => 1, 'workiz' => [['franchise' => 'NJ', 'client_id' => 4512]], 'first_name' => ' ann ', 'last_name' => 'LEE']),
            // A different person on the same line: a household, not a duplicate.
            $this->client(['a1_id' => 2, 'workiz' => [['franchise' => 'NJ', 'client_id' => 4513]], 'first_name' => 'Bob']),
        ]);

        $this->import()->assertSuccessful();

        $this->assertEqualsCanonicalizing(['Ann Lee', 'Bob Lee'], $this->customers()->pluck('name')->all());
        $this->assertContains(['a1_id' => 1, 'status' => 'phone', 'customer_id' => $existing->id], $this->manifest());

        // A phone match links nothing: auto-merging on phone is refused.
        $this->assertSame([4513], $this->links()->pluck('client_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_rows_repeating_an_earlier_row_become_one_customer(): void
    {
        $this->bundle([
            $this->client(['a1_id' => 1]),
            $this->client(['a1_id' => 2, 'first_name' => 'Annie']),
            $this->client(['a1_id' => 3, 'workiz' => [], 'phone' => '203-555-0199', 'first_name' => 'Cy']),
            $this->client(['a1_id' => 4, 'workiz' => [], 'phone' => '(203) 555-0199', 'first_name' => 'Cy']),
        ]);

        $this->import()->assertSuccessful()->expectsOutputToContain('2 repeat an earlier row');

        $this->assertEqualsCanonicalizing(['Ann Lee', 'Cy Lee'], $this->customers()->pluck('name')->all());

        $byA1 = collect($this->manifest())->keyBy('a1_id');
        $this->assertSame('duplicate', $byA1[2]['status']);
        $this->assertSame($byA1[1]['customer_id'], $byA1[2]['customer_id']);
        $this->assertSame($byA1[3]['customer_id'], $byA1[4]['customer_id']);
    }

    public function test_placeholder_contacts_are_blanked_and_never_match(): void
    {
        // A relay line that other people's records already carry.
        $existing = $this->existingCustomer(['name' => 'Ann Lee', 'phone' => '+18187482055']);

        $this->bundle([$this->client(['email' => ' NM@gmail.com', 'phone' => '818-748-2055'])]);

        $this->import()->assertSuccessful()->expectsOutputToContain('emails 1, phones 1');

        $imported = $this->customers()->firstWhere('id', '!=', $existing->id);
        $this->assertNotNull($imported, 'a relay line is not an identity, so it matched nobody');
        $this->assertNull($imported->phone);
        $this->assertNull($imported->email);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->bundle([$this->client(), $this->client(['a1_id' => 2, 'franchise' => 'CT', 'workiz' => [['franchise' => 'CT', 'client_id' => 4512]], 'phone' => null])]);

        $this->import(['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('2 to create')
            ->expectsOutputToContain('Connecticut 1, New Jersey 1')
            ->expectsOutputToContain('Dry run: nothing was written.');

        $this->assertCount(0, $this->customers());
        $this->assertCount(0, $this->links());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => AuditEvent::query()->count()));
    }

    // ── refusals ─────────────────────────────────────────────────

    public static function defects(): array
    {
        return [
            'an unknown key' => [['middle_name' => 'Q']],
            'an unknown franchise' => [['franchise' => 'FL']],
            'no franchise' => [['franchise' => null]],
            'a key in an unknown franchise' => [['workiz' => [['franchise' => 'Full', 'client_id' => 9]]]],
            'a key that is not a number' => [['workiz' => [['franchise' => 'NJ', 'client_id' => '45x']]]],
            'a date that is not one' => [['client_since' => '2025-13-45']],
            'a value too long' => [['city' => str_repeat('x', 256)]],
            'a repeated a1 id' => [['a1_id' => 1]],
        ];
    }

    #[DataProvider('defects')]
    public function test_a_defect_anywhere_refuses_the_whole_run(array $defect): void
    {
        $this->bundle([
            $this->client(['a1_id' => 1]),
            $this->client(array_replace(['a1_id' => 2, 'workiz' => [['franchise' => 'NJ', 'client_id' => 777]]], $defect)),
        ]);

        $this->import()->assertFailed()->expectsOutputToContain('Refused. Nothing was written.');

        $this->assertCount(0, $this->customers());
        $this->assertCount(0, $this->links());
    }

    public function test_a_bundle_in_another_format_is_refused(): void
    {
        $this->bundle([$this->client()], ['format' => 'ezbizi.a1-people/1']);

        $this->import()->assertFailed();

        $this->assertCount(0, $this->customers());
    }

    // ── the run ──────────────────────────────────────────────────

    public function test_the_run_is_audited_once_and_raises_no_automation_event(): void
    {
        $this->bundle([$this->client(['a1_id' => 1]), $this->client(['a1_id' => 2, 'workiz' => [], 'first_name' => 'Bo'])]);

        $this->import()->assertSuccessful();

        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => AutomationEvent::query()->count()));

        $audit = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'customer.imported')->sole());
        $this->assertSame('a1tools', $audit->metadata['source']);
        $this->assertSame(2, $audit->metadata['created']);

        // Positive provenance for a rollback: every id the run created.
        $created = collect($this->manifest())->where('status', 'created');
        $this->assertEqualsCanonicalizing($this->customers()->pluck('id')->all(), $created->pluck('customer_id')->all());
        $this->assertEqualsCanonicalizing($this->links()->pluck('id')->all(), $created->pluck('workiz_client_ids')->flatten()->all());
        $this->assertSame([$audit->metadata['manifest']], Storage::disk('local')->allFiles('a1-imports'));
    }

    public function test_it_will_not_run_beside_the_workiz_projection(): void
    {
        $this->bundle([$this->client()]);
        $held = Cache::lock(ProjectWorkizRecords::LOCK, 60);
        $this->assertTrue($held->get());

        try {
            $this->import()->assertFailed();
        } finally {
            $held->release();
        }

        $this->assertCount(0, $this->customers());
    }

    public function test_the_projection_attaches_a_later_workiz_job_to_the_imported_customer(): void
    {
        $this->bundle([$this->client(['workiz' => [['franchise' => 'NJ', 'client_id' => 1002]]])]);
        $this->import()->assertSuccessful();
        $imported = $this->customers()->sole();

        // The client books again in Workiz: the sweep mirrors the job.
        $payload = require base_path('tests/Fixtures/workiz/job_payload.php');
        $this->assertSame(1002, $payload['ClientId']);
        $this->inTenant($this->t['org'], fn () => WorkizJob::create([
            'franchise_id' => $this->ids['nj'],
            'connection_id' => (string) Str::uuid(),
            'remote_id' => $payload['UUID'],
            'client_id' => $payload['ClientId'],
            'status' => $payload['Status'],
            'job_type' => $payload['JobType'],
            'address' => $payload['Address'],
            'city' => $payload['City'],
            'state' => $payload['State'],
            'postal_code' => $payload['PostalCode'],
            'country' => $payload['Country'],
            'booked_at' => $payload['CreatedDate'],
            'scheduled_for' => $payload['JobDateTime'],
            'payload' => $payload,
            'synced_at' => now(),
        ]));

        $this->artisan('ezbizi:project-workiz')->assertSuccessful();

        $this->assertSame([$imported->id], $this->customers()->pluck('id')->all(), 'no second customer');
        $job = $this->inTenant($this->t['org'], fn () => Job::query()->sole());
        $this->assertSame($imported->id, $job->customer_id);
        $this->assertSame($payload['UUID'], $this->links()->sole()->from_remote_job_id, 'the projection adopted the row');
    }
}
