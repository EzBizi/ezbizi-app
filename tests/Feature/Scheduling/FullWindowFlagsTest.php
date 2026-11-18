<?php

namespace Tests\Feature\Scheduling;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose / desk, 2026-11-17: Maryland's 8-11 took 13 of 4; the one booked into a full window alerts at once. */
class FullWindowFlagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_job_booked_into_a_full_window_alerts_the_booker_and_the_listed_manager_once(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $alpha = $this->provisionTenant('Alpha');
        $booker = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);
        $manager = $this->userWithRole($alpha, 'Field Manager', ['jobs.view']);
        $bystander = $this->userWithRole($alpha, 'Scheduler', ['jobs.view']);
        $this->inTenant($alpha['org'], function () use ($alpha, $booker, $manager) {
            $f = $alpha['franchise'];
            app(SettingsResolver::class)->set('scheduling.full_window_alert_to', $f, 'nobody@example.com, '.strtoupper($manager->email));
            $area = ServiceArea::create(['franchise_id' => $f->id, 'name' => 'Maryland']);
            foreach ([['08:00', '11:00', 1], ['14:00', '17:00', 3]] as [$s, $e, $max]) {
                ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 3, 'start_time' => $s, 'end_time' => $e, 'max_jobs' => $max]);
            }
            WorkizTeamMember::create(['franchise_id' => $f->id, 'remote_id' => 'U1', 'name' => 'Ana Ruiz', 'user_id' => $booker->id, 'payload' => [], 'synced_at' => now()]);
            $c = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'A']);
            foreach ([['FIRST', 201, '2026-11-12 14:00:00'], ['SECOND', 202, '2026-11-13 08:11:00']] as [$id, $serial, $created]) {
                $job = Job::create(['franchise_id' => $f->id, 'customer_id' => $c->id, 'number' => 'J-'.$serial, 'title' => 'J', 'status' => 'Submitted',
                    'currency' => 'USD', 'service_area_id' => $area->id, 'scheduled_for' => '2026-11-18 08:00:00']);
                WorkizJob::create(['franchise_id' => $f->id, 'remote_id' => $id, 'status' => 'Submitted', 'serial_id' => $serial, 'job_id' => $job->id,
                    'created_by' => 'Ana Ruiz', 'payload' => ['CreatedDate' => $created], 'scheduled_for' => '2026-11-18 08:00:00', 'synced_at' => now()]);
            }
        });

        $this->artisan('scheduling:flag-full-windows')->expectsOutputToContain('1 full-window flag raised')->assertSuccessful();
        $this->artisan('scheduling:flag-full-windows')->expectsOutputToContain('0 full-window flags raised')->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($booker, $manager, $bystander) {
            $title = 'Job #202: 8-11 AM already had 1 of 1 when it was booked. Next open that day: 2-5 PM.';
            $this->assertSame([$title], Notification::query()->where('user_id', $booker->id)->pluck('title')->all());
            $this->assertSame([$title], Notification::query()->where('user_id', $manager->id)->pluck('title')->all());
            $this->assertSame(0, Notification::query()->where('user_id', $bystander->id)->count());
        });
    }
}
