<?php

namespace Tests\Feature\Workiz;

use App\Console\Commands\ProjectWorkizRecords;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizClient;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `ezbizi:workiz-merge-twins`: the customers the old-jobs backfill made twice,
 * folded back into the one they are, on Customers' conditions (2026-09-29).
 */
class MergeWorkizTwinsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const FROM = '2026-09-29 18:44:00';

    private const TO = '2026-09-29 21:17:30';

    private array $t;

    private string $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->t = $this->provisionTenant('Alpha');
        $this->other = $this->inTenant($this->t['org'], fn () => Franchise::create([
            'organization_id' => $this->t['org']->id, 'company_id' => $this->t['company']->id, 'name' => 'Beta F2',
        ])->id);
    }

    /** A customer made at $at, as the import or the projection makes one: with Workiz keys and jobs. */
    private function customer(string $at, string $name, string $phone, array $keys = [], int $jobs = 0, ?string $franchise = null): Customer
    {
        Carbon::setTestNow($at);

        try {
            return $this->inTenant($this->t['org'], function () use ($name, $phone, $keys, $jobs, $franchise) {
                $franchise ??= $this->t['franchise']->id;
                $customer = Customer::create([
                    'owning_franchise_id' => $franchise, 'number' => 'C-'.bin2hex(random_bytes(3)),
                    'name' => $name, 'phone' => $phone, 'is_active' => true,
                ]);

                foreach ($keys as $key) {
                    WorkizClient::create(['franchise_id' => $franchise, 'client_id' => $key, 'customer_id' => $customer->id, 'synced_at' => now()]);
                }

                for ($i = 0; $i < $jobs; $i++) {
                    Job::create([
                        'franchise_id' => $franchise, 'customer_id' => $customer->id, 'number' => 'J-'.bin2hex(random_bytes(3)),
                        'title' => 'Old sweep', 'status' => 'Done', 'currency' => 'USD',
                    ]);
                }

                return $customer;
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    private function merge(array $options = [])
    {
        return $this->artisan('ezbizi:workiz-merge-twins', ['from' => self::FROM, 'to' => self::TO, '--wait' => 0] + $options);
    }

    private function live(): Collection
    {
        return $this->inTenant($this->t['org'], fn () => Customer::query()->pluck('id'));
    }

    public function test_a_same_franchise_twin_is_folded_into_the_customer_it_is(): void
    {
        $kept = $this->customer('2026-09-29 18:35:00', 'Ann Lee', '+12015550100', [4512], jobs: 1);
        $twin = $this->customer('2026-09-29 19:10:00', ' ann  LEE', '+12015550100', [4513], jobs: 2);

        $this->merge()->assertSuccessful()->expectsOutputToContain('1 to merge');

        $this->assertEquals([$kept->id], $this->live()->all(), 'the twin is soft-deleted');
        $this->inTenant($this->t['org'], function () use ($kept, $twin) {
            $this->assertSame(3, Job::query()->where('customer_id', $kept->id)->count(), 'its jobs moved');
            $this->assertSame([4512, 4513], WorkizClient::query()->where('customer_id', $kept->id)->orderBy('client_id')->pluck('client_id')->map(fn ($i) => (int) $i)->all(), 'and its key, so the next job finds the kept one');
            $this->assertNotNull(Customer::withTrashed()->find($twin->id)?->deleted_at);
        });

        // Every move written down, so a wrong one can be undone.
        $audit = $this->inTenant($this->t['org'], fn () => AuditEvent::query()->where('action', 'customer.twins_merged')->sole());
        $this->assertEquals([['twin' => $twin->id, 'kept' => $kept->id, 'jobs' => 2]], $audit->metadata['merged']);
        $line = json_decode(trim(Storage::disk('local')->get($audit->metadata['manifest'])), true);
        $this->assertSame([$twin->id, $kept->id, 2, 1], [$line['twin'], $line['kept'], count($line['jobs']), count($line['workiz_clients'])]);
        $this->assertSame(0, $this->inTenant($this->t['org'], fn () => AutomationEvent::query()->count()));
    }

    public function test_a_twin_in_another_franchise_waits_for_jose(): void
    {
        $kept = $this->customer('2026-09-29 18:35:00', 'Ann Lee', '+12015550100', [4512]);
        $twin = $this->customer('2026-09-29 19:10:00', 'Ann Lee', '+12015550100', [77], jobs: 1, franchise: $this->other);

        $this->merge()->assertSuccessful()->expectsOutputToContain('1 in another franchise held for Jose');
        $this->assertCount(2, $this->live());

        $this->merge(['--cross-franchise' => true])->assertSuccessful();
        $this->assertEquals([$kept->id], $this->live()->all());
    }

    public function test_what_is_not_certain_is_left_alone_and_reported(): void
    {
        // Two older customers with the same phone and name: which one?
        $this->customer('2026-09-29 10:00:00', 'Bo Lee', '+12015550111', [1]);
        $this->customer('2026-09-29 11:00:00', 'Bo Lee', '+12015550111', [2]);
        $several = $this->customer('2026-09-29 19:00:00', 'Bo Lee', '+12015550111', [3]);

        // A person corrected it after the projection made it.
        $this->customer('2026-09-29 10:00:00', 'Cy Lee', '+12015550122', [4]);
        $touched = $this->customer('2026-09-29 19:00:00', 'Cy Lee', '+12015550122', [5]);
        Carbon::setTestNow('2026-09-29 22:00:00');
        $this->inTenant($this->t['org'], fn () => $touched->forceFill(['email' => 'cy@example.test'])->save());
        Carbon::setTestNow();

        // Something else points at it, which a soft-delete would strand.
        $this->customer('2026-09-29 10:00:00', 'Di Lee', '+12015550133', [6]);
        $named = $this->customer('2026-09-29 19:00:00', 'Di Lee', '+12015550133', [7]);
        $this->inTenant($this->t['org'], fn () => app(AuditLogger::class)->log('customer.updated', $named));

        // Made outside the window: not the backfill's.
        $this->customer('2026-09-29 10:00:00', 'Ed Lee', '+12015550144', [8]);
        $late = $this->customer('2026-09-30 08:00:00', 'Ed Lee', '+12015550144', [9]);

        $before = $this->live()->sort()->values()->all();

        $this->merge()->assertSuccessful()
            ->expectsOutputToContain('0 to merge')
            ->expectsOutputToContain("several: {$several->id} matches")
            ->expectsOutputToContain("touched: {$touched->id}")
            ->expectsOutputToContain("named: {$named->id}");

        $this->assertSame($before, $this->live()->sort()->values()->all());
        $this->assertContains($late->id, $this->live()->all());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->customer('2026-09-29 18:35:00', 'Ann Lee', '+12015550100', [4512]);
        $this->customer('2026-09-29 19:10:00', 'Ann Lee', '+12015550100', [4513], jobs: 1);

        $this->merge(['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('[dry run] 1 twins: 1 to merge');

        $this->assertCount(2, $this->live());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_it_will_not_run_beside_the_workiz_projection(): void
    {
        $this->customer('2026-09-29 18:35:00', 'Ann Lee', '+12015550100', [4512]);
        $this->customer('2026-09-29 19:10:00', 'Ann Lee', '+12015550100', [4513]);
        $held = Cache::lock(ProjectWorkizRecords::LOCK, 60);
        $this->assertTrue($held->get());

        try {
            $this->merge()->assertFailed();
        } finally {
            $held->release();
        }

        $this->assertCount(2, $this->live());
    }
}
