<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk, 2026-11-17: a full window or a late Friday refuses a booking; scheduling.overbook books it with a reason. */
class BookingGateTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** Wed 2026-11-18 8-11 takes one, already booked; Friday 11-2 is open. */
    private function setUpArea(): array
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $alpha = $this->provisionTenant('Alpha');
        $f = $alpha['franchise'];
        [$area, $customer] = $this->inTenant($alpha['org'], function () use ($alpha, $f) {
            $area = ServiceArea::create(['organization_id' => $alpha['org']->id, 'franchise_id' => $f->id, 'name' => 'Maryland']);
            foreach ([[3, '08:00', '11:00'], [3, '11:00', '14:00'], [5, '11:00', '14:00'], [5, '14:00', '17:00']] as [$d, $s, $e]) {
                ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $d, 'start_time' => $s, 'end_time' => $e, 'max_jobs' => 1]);
            }
            $customer = Customer::create(['owning_franchise_id' => $f->id, 'number' => 'C-1', 'name' => 'Bob', 'address_line1' => '12 Savin Ave']);
            Job::create(['franchise_id' => $f->id, 'customer_id' => $customer->id, 'number' => 'J-0', 'title' => 'Seat', 'status' => 'x',
                'currency' => 'USD', 'service_area_id' => $area->id, 'scheduled_for' => '2026-11-18 08:00:00']);

            return [$area, $customer];
        });

        return [$alpha, $area, $customer];
    }

    public function test_a_full_window_is_refused_with_the_next_three(): void
    {
        [$alpha, $area, $customer] = $this->setUpArea();
        $dispatcher = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view', 'jobs.create']);
        $post = fn ($user, string $start, array $x = []) => $this->signedInAs($user)->postJson('/api/v1/jobs/book', $x + [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id, 'service_area_id' => $area->id,
            'title' => 'Sweep', 'scheduled' => true, 'start' => $start]);

        $res = $post($dispatcher, '2026-11-18 09:00', ['override_reason' => 'customer insists'])->assertUnprocessable();
        $this->assertSame('The 08:00-11:00 window on Wed Nov 18 in Maryland is full (1 of 1).', $res->json('message'));
        $this->assertFalse($res->json('can_override'), 'a reason without the permission is still refused');
        $this->assertSame([['2026-11-18', '11:00'], ['2026-11-20', '11:00'], ['2026-11-25', '08:00']],
            array_map(fn ($w) => [$w['date'], $w['start_time']], $res->json('next_windows')), 'Friday 2-5 is never offered');

        $post($dispatcher, '2026-11-20 14:00')->assertUnprocessable()->assertJsonPath('errors.scheduled_for.0',
            'Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.');
        $post($dispatcher, '2026-11-18 11:00')->assertCreated();

        // The admin holds scheduling.overbook: a reason books it, on the job and to the field manager.
        $manager = $this->userWithRole($alpha, 'Field Manager', ['jobs.view']);
        $post($alpha['admin'], '2026-11-18 09:30')->assertUnprocessable()->assertJsonPath('can_override', true);
        $id = $post($alpha['admin'], '2026-11-18 09:30', ['override_reason' => 'Repeat customer'])->assertCreated()->json('data.job.id');
        $this->inTenant($alpha['org'], function () use ($id, $manager) {
            $this->assertStringStartsWith('Booked over the limit by ', Job::query()->find($id)->description);
            $this->assertStringContainsString('Repeat customer', Notification::query()->where('user_id', $manager->id)->value('title'));
        });
    }
}
