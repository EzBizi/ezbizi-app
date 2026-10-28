<?php

namespace Tests\Feature\Scheduling;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\SchedulingTimeSlot;
use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The organization's time windows (Jose, 2026-09-24: "go ahead and add the
 * Time Slots option").
 *
 * The case that matters most is the one a screen cannot show: changing a
 * window's hours has to move every capacity row on it, or the rows keep being
 * counted on hours the editor no longer offers.
 */
class SchedulingTimeSlotsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function orgEditor(array $tenant)
    {
        return $this->userWithRole($tenant, 'Owner', [
            'scheduling.view',
            'scheduling.settings.view',
        ]);
    }

    private function area(array $tenant, string $name = 'Cleveland'): ServiceArea
    {
        app(TenantContext::class)->set($tenant['org']);

        return ServiceArea::create(['franchise_id' => $tenant['franchise']->id, 'name' => $name]);
    }

    /** THE CONTROL. Somebody who works at organization level sets the windows. */
    public function test_an_organization_level_user_states_the_windows_in_order(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->orgEditor($tenant);

        foreach ([['15:00', '18:00'], ['10:00', '13:00'], ['13:00', '15:00']] as [$start, $end]) {
            $this->signedInAs($owner)
                ->postJson('/api/v1/scheduling/time-slots', ['start_time' => $start, 'end_time' => $end])
                ->assertCreated();
        }

        $this->signedInAs($owner)
            ->getJson('/api/v1/scheduling/time-slots')
            ->assertOk()
            ->assertJsonPath('can_edit', true)
            ->assertJsonPath('data.0.start_time', '10:00')
            ->assertJsonPath('data.1.start_time', '13:00')
            ->assertJsonPath('data.2.start_time', '15:00')
            ->assertJsonPath('data.2.end_time', '18:00');
    }

    /**
     * Windows may TOUCH and may not OVERLAP. The first request is the control:
     * 13:00–15:00 beside 10:00–13:00 is the shape Jose described and must be
     * accepted, or the refusal below proves nothing about overlap.
     */
    public function test_touching_windows_are_fine_and_overlapping_ones_are_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->orgEditor($tenant);

        $this->signedInAs($owner)->postJson('/api/v1/scheduling/time-slots', ['start_time' => '10:00', 'end_time' => '13:00'])->assertCreated();
        $this->signedInAs($owner)->postJson('/api/v1/scheduling/time-slots', ['start_time' => '13:00', 'end_time' => '15:00'])->assertCreated();

        $message = $this->signedInAs($owner)
            ->postJson('/api/v1/scheduling/time-slots', ['start_time' => '12:00', 'end_time' => '14:00'])
            ->assertUnprocessable()
            ->json('errors.start_time.0');

        $this->assertStringContainsString('overlaps', $message);

        $this->signedInAs($owner)
            ->postJson('/api/v1/scheduling/time-slots', ['start_time' => '16:00', 'end_time' => '16:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');
    }

    /**
     * CHANGING A WINDOW MOVES THE CAPACITY ON IT.
     *
     * Every one of A-1's 108 capacity rows sat on 08:00–12:00, the window the
     * old editor hard-coded. Moving that window to 10:00–13:00 must take the
     * rows and their numbers with it — and leave a row on a DIFFERENT window
     * alone, which is the half that proves the move is by window and not a
     * sweep of the table.
     */
    public function test_changing_a_window_moves_every_capacity_row_on_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->orgEditor($tenant);
        $area = $this->area($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $morning = SchedulingTimeSlot::create(['start_time' => '08:00', 'end_time' => '12:00']);
        SchedulingTimeSlot::create(['start_time' => '13:00', 'end_time' => '17:00']);

        foreach ([1, 2] as $weekday) {
            ServiceAreaSlot::create([
                'service_area_id' => $area->id, 'weekday' => $weekday,
                'start_time' => '08:00', 'end_time' => '12:00', 'max_jobs' => 4,
            ]);
        }
        ServiceAreaSlot::create([
            'service_area_id' => $area->id, 'weekday' => 1,
            'start_time' => '13:00', 'end_time' => '17:00', 'max_jobs' => 2,
        ]);

        $this->signedInAs($owner)
            ->patchJson("/api/v1/scheduling/time-slots/{$morning->id}", ['start_time' => '10:00', 'end_time' => '13:00'])
            ->assertOk();

        app(TenantContext::class)->set($tenant['org']);
        $rows = ServiceAreaSlot::query()->orderBy('weekday')->orderBy('start_time')->get()
            ->map(fn ($s) => [$s->weekday, substr($s->start_time, 0, 5), substr($s->end_time, 0, 5), $s->max_jobs])
            ->all();

        $this->assertSame([
            [1, '10:00', '13:00', 4],
            [1, '13:00', '17:00', 2],
            [2, '10:00', '13:00', 4],
        ], $rows, 'The morning rows must follow the window, keep their numbers, and the afternoon must not move.');
    }

    /** Removing a window takes the capacity on it, and only that. */
    public function test_removing_a_window_removes_its_capacity_everywhere(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->orgEditor($tenant);
        $area = $this->area($tenant);

        app(TenantContext::class)->set($tenant['org']);
        $late = SchedulingTimeSlot::create(['start_time' => '16:00', 'end_time' => '18:00']);
        SchedulingTimeSlot::create(['start_time' => '10:00', 'end_time' => '13:00']);

        ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 3, 'start_time' => '16:00', 'end_time' => '18:00', 'max_jobs' => 1]);
        ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => 3, 'start_time' => '10:00', 'end_time' => '13:00', 'max_jobs' => 3]);

        // A removal that does not name the window is refused and removes
        // nothing (Jose: "anyone could click by accident").
        $this->signedInAs($owner)->deleteJson("/api/v1/scheduling/time-slots/{$late->id}")->assertStatus(422);
        $this->signedInAs($owner)->deleteJson("/api/v1/scheduling/time-slots/{$late->id}?confirm=10:00-13:00")->assertStatus(422);
        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(2, ServiceAreaSlot::query()->count());

        $this->signedInAs($owner)->getJson('/api/v1/scheduling/time-slots')
            ->assertJsonPath('data.1.areas', 1);
        // Counted in the view: another company's area on the same window is not this company's.
        $other = $this->inTenant($tenant['org'], fn () => Company::create(['name' => 'Bravo Co']));
        $theirs = $this->inTenant($tenant['org'], fn () => Franchise::create(['company_id' => $other->id, 'name' => 'B1']));
        $this->inTenant($tenant['org'], fn () => ServiceAreaSlot::create(['service_area_id' => ServiceArea::create(['franchise_id' => $theirs->id, 'name' => 'B area'])->id, 'weekday' => 3, 'start_time' => '16:00', 'end_time' => '18:00', 'max_jobs' => 1]));
        $this->signedInAs($owner)->getJson('/api/v1/scheduling/time-slots')->assertJsonPath('data.1.areas', 2);
        $this->signedInAs($owner)->withHeader('X-EzBizi-View', 'company:'.$tenant['company']->id)
            ->getJson('/api/v1/scheduling/time-slots')->assertJsonPath('data.1.areas', 1);
        $this->signedInAs($owner)->withHeader('X-EzBizi-View', 'company:'.$other->id)
            ->getJson('/api/v1/scheduling/time-slots')->assertJsonPath('data.1.areas', 1)->assertJsonPath('data.0.areas', 0);
        $this->withoutHeader('X-EzBizi-View');
        app(TenantContext::class)->set($tenant['org']);
        ServiceAreaSlot::query()->whereIn('service_area_id', ServiceArea::query()->where('franchise_id', $theirs->id)->select('id'))->delete();

        $this->signedInAs($owner)->deleteJson("/api/v1/scheduling/time-slots/{$late->id}?confirm=16:00-18:00")->assertOk();

        app(TenantContext::class)->set($tenant['org']);
        $this->assertSame(['10:00'], ServiceAreaSlot::query()->pluck('start_time')->map(fn ($t) => substr($t, 0, 5))->all());
    }

    /**
     * A BRANCH-LEVEL USER READS THE WINDOWS AND CANNOT MOVE THEM.
     *
     * A window applies to every franchise. Somebody assigned to one branch may
     * set how many jobs their areas take, and must not move the hours of a
     * branch they cannot see. The refusal is asserted by its SENTENCE, because
     * a bare 403 would pass just as well if the permission middleware refused
     * them for some other reason.
     */
    public function test_a_branch_level_user_can_read_the_windows_but_not_change_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        app(TenantContext::class)->set($tenant['org']);
        SchedulingTimeSlot::create(['start_time' => '10:00', 'end_time' => '13:00']);

        $manager = $this->userWithRole($tenant, 'Branch manager', [
            'scheduling.view', 'scheduling.settings.view',
        ], onlyAt: $tenant['franchise']);

        $this->signedInAs($manager)
            ->getJson('/api/v1/scheduling/time-slots')
            ->assertOk()
            ->assertJsonPath('can_edit', false)
            ->assertJsonPath('data.0.start_time', '10:00');

        $message = $this->signedInAs($manager)
            ->postJson('/api/v1/scheduling/time-slots', ['start_time' => '13:00', 'end_time' => '15:00'])
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('every branch', $message);
    }

    /**
     * The backfill: every window already in use becomes a time slot, so no
     * existing capacity row points at a window the list does not offer.
     *
     * Runs the migration FILE rather than trusting RefreshDatabase, which ran
     * it against an empty table and so backfilled nothing.
     */
    public function test_the_migration_lists_every_window_already_in_use(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $area = $this->area($tenant);

        app(TenantContext::class)->set($tenant['org']);
        foreach ([1, 2, 3] as $weekday) {
            ServiceAreaSlot::create(['service_area_id' => $area->id, 'weekday' => $weekday, 'start_time' => '08:00', 'end_time' => '12:00', 'max_jobs' => 1]);
        }

        Schema::drop('scheduling_time_slots');

        $migration = require database_path('migrations/2026_09_24_100000_create_scheduling_time_slots.php');
        $migration->up();

        $windows = DB::table('scheduling_time_slots')->get(['organization_id', 'start_time', 'end_time']);

        $this->assertCount(1, $windows, 'Three rows on one window make one time slot, not three.');
        $this->assertSame($tenant['org']->id, $windows->first()->organization_id);
        $this->assertSame('08:00', substr($windows->first()->start_time, 0, 5));
        $this->assertSame('12:00', substr($windows->first()->end_time, 0, 5));
    }
}
