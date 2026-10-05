<?php

namespace Tests\Feature\TimeClock;

use App\Models\Permission;
use App\Models\Role;
use App\Models\TimeClockCorrectionRequest;
use App\Models\TimeClockShift;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\WorkHoursGlance;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Work Hours leaves Human Resources (Jose, 2026-09-24).
 *
 * The move must not narrow anybody. That is the direction nothing reports:
 * a manager opens the app on Friday and the hours they read on Thursday are
 * simply not there.
 */
class WorkHoursModuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * The migration, run as a migration — the FILE required and `up()` called,
     * against a role holding the four OLD keys exactly as production has them.
     */
    public function test_every_old_grant_is_copied_to_its_new_key(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $role = $this->inTenant($alpha['org'], fn () => Role::create([
            'organization_id' => $alpha['org']->id,
            'name' => 'Office manager',
            'is_system' => false,
        ]));

        // Written as rows, not through the catalogue: the old keys are
        // declared nowhere now, which is exactly the state production is in.
        foreach (['hr.hours.view', 'hr.hours.all', 'hr.hours.correct', 'hr.pto.view', 'organizations.edit'] as $key) {
            $id = DB::table('permissions')->where('key', $key)->value('id');
            if ($id === null) {
                $id = (string) Str::uuid();
                [$module, $action] = explode('.', $key, 2);
                DB::table('permissions')->insert([
                    'id' => $id, 'key' => $key, 'module' => $module, 'action' => $action,
                    'label' => $key, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $id]);
        }

        $migration = require database_path('migrations/2026_09_24_130000_work_hours_leaves_hr.php');
        $migration->up();

        $held = Permission::query()
            ->whereIn('id', DB::table('permission_role')->where('role_id', $role->id)->pluck('permission_id'))
            ->pluck('key')
            ->all();

        foreach ([
            'work_hours.view',
            'work_hours.general.view',
            'work_hours.all',
            'work_hours.manage',
            'work_hours.pto.view',
            'work_hours.settings.view',
        ] as $key) {
            $this->assertContains($key, $held, "{$key} was not copied");
        }

    }

    /** And a role that held none of them gains none: copying is not granting. */
    public function test_a_role_with_none_of_the_old_keys_gains_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $migration = require database_path('migrations/2026_09_24_130000_work_hours_leaves_hr.php');
        $migration->up();

        $keys = $this->signedInAs($tech)->getJson('/api/v1/me')->assertOk()->json('data.permissions');

        $this->assertEmpty(
            array_filter($keys, fn ($key) => str_starts_with($key, 'work_hours.')),
            'somebody who only punches must not be handed a Work Hours module'
        );
    }

    public function test_the_general_tab_alone_reads_the_week_and_nothing_of_hr(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('time_clock.enabled', $alpha['org'], true));

        $reader = $this->userWithRole($alpha, 'Reader', ['work_hours.general.view']);

        $this->signedInAs($reader)->getJson('/api/v1/hr/time-clock/week')->assertOk();
        $this->signedInAs($reader)->getJson('/api/v1/hr/people')->assertForbidden();
    }

    /**
     * Setting somebody's schedule from Work Hours: `manage`, within reach. The
     * control is the same person refused without it.
     */
    public function test_manage_sets_another_persons_schedule(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $manager = $this->userWithRole($alpha, 'Manager', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'm@alpha.test');
        $reader = $this->userWithRole($alpha, 'Reader', ['work_hours.general.view', 'work_hours.all'], 'r@alpha.test');
        $tech = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 't@alpha.test');

        // All seven, as the editor sends them: a missing day is refused, so
        // "no Tuesday" and "Tuesday off" cannot be confused.
        $days = [
            'monday' => ['start' => '08:00', 'end' => '17:00'],
            'tuesday' => null, 'wednesday' => null, 'thursday' => null,
            'friday' => null, 'saturday' => null, 'sunday' => null,
        ];

        $this->signedInAs($reader)->patchJson("/api/v1/hr/people/{$tech->id}/schedule", ['days' => $days])->assertForbidden();

        $this->signedInAs($manager)->patchJson("/api/v1/hr/people/{$tech->id}/schedule", ['days' => $days])->assertOk();

        $this->assertSame('08:00', $tech->fresh()->work_schedule['days']['monday']['start']);
    }

    /**
     * The dashboard box counts the people the Work Hours screen shows this
     * reader and nobody else: a branch manager's box does not count another
     * branch, and the control is the same box counting their own.
     */
    public function test_the_box_counts_only_the_people_the_screen_shows(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $elsewhere = $this->extraFranchise($alpha, 'Alpha F2');

        $branch = $this->userWithRole($alpha, 'Branch', ['work_hours.general.view', 'work_hours.all'], 'b@alpha.test', onlyAt: $alpha['franchise']);
        $mine = $this->userWithRole($alpha, 'Mine', ['time_clock.view'], 'mine@alpha.test', onlyAt: $alpha['franchise']);
        $theirs = $this->userWithRole($alpha, 'Theirs', ['time_clock.view'], 'theirs@alpha.test', onlyAt: $elsewhere);

        $this->inTenant($alpha['org'], function () use ($alpha, $mine, $theirs) {
            foreach ([$mine, $theirs] as $person) {
                TimeClockShift::query()->create([
                    'organization_id' => $alpha['org']->id,
                    'user_id' => $person->id,
                    'clocked_in_at' => now()->subHour(),
                    'scheduled_end_at' => now()->addHours(3),
                ]);
            }
        });

        $data = app(WorkHoursGlance::class)
            ->resolve($branch, DashboardPeriod::for($branch), [])
            ->toArray()['data'];

        $this->assertFalse($data['empty']);
        $this->assertSame(1, collect($data['slices'])->firstWhere('label', 'Clocked in')['total'], 'their own branch, and not the other one');
    }

    /**
     * The figures: who is on the clock now (a snapshot), and hours clocked
     * against the same elapsed span yesterday. The shift yesterday AFTER this
     * time of day is outside "yesterday so far" and must not count.
     */
    public function test_the_figures_compare_hours_with_the_same_span_yesterday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $alpha = $this->provisionTenant('Alpha');
        $worker = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');
        $other = $this->userWithRole($alpha, 'Tech 2', ['time_clock.view'], 'tech2@alpha.test');

        $period = DashboardPeriod::for($alpha['admin'], 'day');
        $before = $period->previous();

        $this->inTenant($alpha['org'], function () use ($alpha, $worker, $other, $period, $before) {
            $shift = fn ($who, $in, $out) => TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id,
                'user_id' => $who->id,
                'clocked_in_at' => $in,
                'clocked_out_at' => $out,
                'scheduled_end_at' => $in->copy()->addHours(8),
            ]);

            // Today: two closed hours, and half an hour still open.
            $shift($worker, $period->startsOn->copy()->utc()->addHour(), $period->startsOn->copy()->utc()->addHours(3));
            $shift($other, now()->subMinutes(30), null);

            // Yesterday so far: three hours. And one after this time of day.
            $shift($worker, $before->startsOn->copy()->utc()->addHour(), $before->startsOn->copy()->utc()->addHours(4));
            $shift($other, $before->endsOn->copy()->utc()->addHour(), $before->endsOn->copy()->utc()->addHours(2));
        });

        $figures = app(WorkHoursGlance::class)
            ->resolve($alpha['admin'], $period, [])
            ->toArray()['data']['figures'];

        $this->assertSame([
            ['key' => 'on_the_clock', 'label' => 'clocked in', 'value' => 1, 'previous' => null],
            ['key' => 'hours', 'label' => 'hours clocked today', 'value' => 2, 'previous' => 3],
        ], $figures);
    }

    /**
     * Attention: a shift open past its end (the sweep failed) is bad; waiting
     * correction requests are caution, for a reader who can approve them. The
     * control is the same box before either exists: no attention at all.
     */
    public function test_the_box_flags_a_stuck_shift_and_waiting_requests(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $worker = $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test');
        $glance = fn () => app(WorkHoursGlance::class)
            ->resolve($alpha['admin'], DashboardPeriod::for($alpha['admin'], 'day'), [])->toArray()['data'];

        $this->assertArrayNotHasKey('attention', $glance());

        $this->inTenant($alpha['org'], function () use ($alpha, $worker) {
            $shift = TimeClockShift::query()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $worker->id,
                'clocked_in_at' => now()->subHours(9), 'scheduled_end_at' => now()->subHour(),
            ]);
            TimeClockCorrectionRequest::query()->create([
                'organization_id' => $alpha['org']->id, 'user_id' => $worker->id, 'shift_id' => $shift->id,
                'was_in_at' => $shift->clocked_in_at, 'clocked_in_at' => now()->subHours(10),
                'reason' => 'Came in early.', 'status' => 'pending',
            ]);
        });

        $this->assertSame([
            ['tone' => 'bad', 'text' => '1 shift is still open past its end.'],
            ['tone' => 'caution', 'text' => '1 correction request is waiting.'],
        ], $glance()['attention']);
    }
}
