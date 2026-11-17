<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Task;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Jobs\JobAddress;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Dispatchers, 2026-11-17: no house number refuses a booking; Workiz jobs are flagged. */
class JobAddressFlagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_house_number_rule(): void
    {
        foreach (['12 Savin Ave', ' 4B Knox Ln', 'N1234 County Rd', 'PO Box 9'] as $ok) {
            $this->assertTrue(JobAddress::hasHouseNumber($ok), $ok);
        }
        foreach (['Savin Ave', 'Haverhill', 'Knox Ln 12', ''] as $bad) {
            $this->assertFalse(JobAddress::hasHouseNumber($bad), $bad);
        }
    }

    public function test_a_job_for_a_customer_with_no_house_number_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        [$bad, $good] = $this->inTenant($alpha['org'], fn () => [
            Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-1', 'name' => 'A', 'address_line1' => 'Savin Ave'])->id,
            Customer::create(['owning_franchise_id' => $alpha['franchise']->id, 'number' => 'C-2', 'name' => 'B', 'address_line1' => '12 Savin Ave'])->id,
        ]);
        $post = fn (string $c) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/jobs',
            ['franchise_id' => $alpha['franchise']->id, 'customer_id' => $c, 'title' => 'Sweep', 'status' => 'scheduled']);

        $post($bad)->assertUnprocessable()->assertJsonPath('errors.address_line1.0',
            'The address "Savin Ave" has no house number. Add it (e.g. "12 Savin Ave") before booking.');
        $post($good)->assertCreated();
    }

    public function test_the_sweep_flags_once_to_the_booker_and_the_branch_board(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $alpha = $this->provisionTenant('Alpha');
        $booker = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);
        $this->inTenant($alpha['org'], function () use ($alpha, $booker) {
            $f = $alpha['franchise'];
            WorkizTeamMember::create(['franchise_id' => $f->id, 'remote_id' => 'U1', 'name' => 'Ana Ruiz', 'user_id' => $booker->id, 'payload' => [], 'synced_at' => now()]);
            $c = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'A']);
            $job = Job::create(['franchise_id' => $f->id, 'customer_id' => $c->id, 'number' => 'J-1', 'title' => 'J', 'status' => 'x', 'currency' => 'USD']);
            $mk = fn (string $id, array $a) => WorkizJob::create($a + ['franchise_id' => $f->id, 'remote_id' => $id, 'status' => 'x',
                'created_by' => 'Ana Ruiz', 'payload' => [], 'synced_at' => now()]);
            $w = $mk('BAD', ['address' => 'Savin Ave', 'postal_code' => '06516', 'serial_id' => 101]);
            $w->job_id = $job->id;
            $w->save();
            $mk('LATE', ['address' => '12 Savin Ave', 'postal_code' => '06516', 'serial_id' => 102, 'scheduled_for' => '2026-11-20 13:00:00']);
            $mk('THREE', ['address' => '12 Savin Ave', 'postal_code' => '06516', 'serial_id' => 104, 'scheduled_for' => '2026-11-20 15:00:00']);
            // The controls: complete address, Friday inside the window.
            $mk('FINE', ['address' => '12 Savin Ave', 'postal_code' => '06516', 'serial_id' => 103, 'scheduled_for' => '2026-11-20 12:00:00']);
        });

        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('3 flags raised')->assertSuccessful();
        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('0 flags raised')->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($booker) {
            $titles = Notification::query()->where('user_id', $booker->id)->pluck('title')->sort()->values()->all();
            $this->assertSame([
                'Job #101: Address needs fixing (no house number): "Savin Ave, 06516"',
                'Job #102: Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.',
                'Job #104: Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.',
            ], $titles);
            $task = Task::query()->where('title', 'like', 'Job #101:%')->firstOrFail();
            $this->assertSame(['job', true], [$task->about_type, $task->about_id !== null]);
            $this->assertSame(3, Task::query()->where('title', 'like', 'Job #%')->count());
        });
    }

    public function test_a_switched_off_check_flags_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('jobs.flag_incomplete_address', $alpha['org'], false);
            WorkizJob::create(['franchise_id' => $alpha['franchise']->id, 'remote_id' => 'BAD', 'status' => 'x', 'address' => 'Savin Ave',
                'postal_code' => '06516', 'payload' => [], 'synced_at' => now()]);
        });

        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('0 flags raised')->assertSuccessful();
    }
}
