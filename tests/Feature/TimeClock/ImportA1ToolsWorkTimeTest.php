<?php

namespace Tests\Feature\TimeClock;

use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `worktime:import-a1-tools` — A1 Tools clock-ins and day statuses into Work
 * Hours (docs/A1-TOOLS-PEOPLE-MIGRATION.md, item 2).
 */
class ImportA1ToolsWorkTimeTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;

    private User $ana;

    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-29 18:00:00', 'UTC'));

        $this->alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($this->alpha['org'], function () {
            app(SettingsResolver::class)->set('timezone', $this->alpha['org'], 'America/New_York');
        });
        $this->ana = $this->userWithRole($this->alpha, 'Tech A', ['time_clock.view'], 'ana@alpha.test');
        $this->ben = $this->userWithRole($this->alpha, 'Tech B', ['time_clock.view'], 'ben@alpha.test');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closed_shifts_import_in_utc_and_a_second_run_writes_nothing_new(): void
    {
        $file = $this->bundle([
            ['id' => 1, 'email' => 'ANA@alpha.test ', 'clock_in' => '2026-09-01 12:00:00', 'clock_out' => '2026-09-01 20:30:00'],
            ['id' => 2, 'email' => 'gone@alpha.test', 'clock_in' => '2026-09-01 12:00:00', 'clock_out' => '2026-09-01 20:00:00'],
        ]);

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha'])->assertSuccessful();

        $shift = $this->shifts($this->ana)->sole();
        $this->assertSame('2026-09-01 12:00:00', $shift->clocked_in_at->toDateTimeString(), 'A1 stores UTC; stored as UTC');
        $this->assertSame('2026-09-01 20:30:00', $shift->clocked_out_at->toDateTimeString());
        $this->assertSame('a1tools:1', $shift->import_ref);

        // Former staff have no account and are skipped, not created.
        $this->assertSame(1, TimeClockShift::query()->withoutGlobalScopes()->count());

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha'])
            ->expectsOutputToContain('shifts_already_imported: 1')
            ->assertSuccessful();
        $this->assertSame(1, TimeClockShift::query()->withoutGlobalScopes()->count());
    }

    /**
     * EzBizi wins an overlap. Ten of the twelve people in both systems punched
     * both clocks, seconds apart, since 2026-08-25 — the A1 copy is skipped.
     * The control is an A1 shift on another day, which does import.
     */
    public function test_an_a1_shift_overlapping_an_ezbizi_one_is_skipped(): void
    {
        $this->inTenant($this->alpha['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $this->alpha['org']->id,
            'user_id' => $this->ana->id,
            'clocked_in_at' => '2026-09-02 12:00:05',
            'clocked_out_at' => '2026-09-02 21:00:00',
        ]));

        $file = $this->bundle([
            ['id' => 10, 'email' => 'ana@alpha.test', 'clock_in' => '2026-09-02 12:00:00', 'clock_out' => '2026-09-02 21:00:02'],
            ['id' => 11, 'email' => 'ana@alpha.test', 'clock_in' => '2026-09-03 12:00:00', 'clock_out' => '2026-09-03 20:00:00'],
            // Two A1 rows overlapping each other: the earlier stays.
            ['id' => 12, 'email' => 'ben@alpha.test', 'clock_in' => '2026-09-04 12:00:00', 'clock_out' => '2026-09-04 20:00:00'],
            ['id' => 13, 'email' => 'ben@alpha.test', 'clock_in' => '2026-09-04 13:00:00', 'clock_out' => '2026-09-04 14:00:00'],
        ]);

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha'])
            ->expectsOutputToContain('shifts_overlap_ezbizi: 1')
            ->expectsOutputToContain('shifts_overlap_a1: 1')
            ->assertSuccessful();

        $this->assertSame([null, 'a1tools:11'], $this->shifts($this->ana)->pluck('import_ref')->all());
        $this->assertSame(['a1tools:12'], $this->shifts($this->ben)->pluck('import_ref')->all());
    }

    /**
     * An open A1 shift arrives open WITH an end, so the sweep can see it; one
     * older than the cap arrives closed at the cap. Never an open row with a
     * null end — that is the row nothing ever closes.
     */
    public function test_open_shifts_get_an_end_and_a_stale_one_is_closed_at_the_cap(): void
    {
        $file = $this->bundle([
            ['id' => 20, 'email' => 'ana@alpha.test', 'clock_in' => '2026-09-29 13:00:00', 'clock_out' => null],
            ['id' => 21, 'email' => 'ben@alpha.test', 'clock_in' => '2026-09-20 13:00:00', 'clock_out' => null],
        ]);

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha'])->assertSuccessful();

        $open = $this->shifts($this->ana)->sole();
        $this->assertNull($open->clocked_out_at);
        $this->assertSame('2026-09-30 05:00:00', $open->scheduled_end_at->toDateTimeString(), 'the 16-hour cap');

        $stale = $this->shifts($this->ben)->sole();
        $this->assertSame('2026-09-21 05:00:00', $stale->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $stale->ended_by);
    }

    /** Absent only on a day with no hours and nothing already said; `other` has no tag. */
    public function test_absent_days_are_tagged_except_where_there_are_hours(): void
    {
        $file = $this->bundle(
            [['id' => 30, 'email' => 'ana@alpha.test', 'clock_in' => '2026-09-08 13:00:00', 'clock_out' => '2026-09-08 21:00:00']],
            [
                ['email' => 'ana@alpha.test', 'date' => '2026-09-07', 'status' => 'absent'],
                ['email' => 'ana@alpha.test', 'date' => '2026-09-08', 'status' => 'absent'],   // she worked
                ['email' => 'ana@alpha.test', 'date' => '2026-09-09', 'status' => 'other'],
            ],
        );

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha'])
            ->expectsOutputToContain('days_tagged: 1')
            ->expectsOutputToContain('days_has_hours: 1')
            ->expectsOutputToContain('days_other: 1')
            ->assertSuccessful();

        $days = TimeClockDay::query()->withoutGlobalScopes()->get();
        $this->assertCount(1, $days);
        $this->assertSame('2026-09-07', $days[0]->date->toDateString());
        $this->assertSame('absent', $days[0]->tag);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $file = $this->bundle(
            [['id' => 40, 'email' => 'ana@alpha.test', 'clock_in' => '2026-09-01 12:00:00', 'clock_out' => '2026-09-01 20:00:00']],
            [['email' => 'ben@alpha.test', 'date' => '2026-09-07', 'status' => 'absent']],
        );

        $this->artisan('worktime:import-a1-tools', ['file' => $file, '--organization' => 'Alpha', '--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('shifts_imported: 1')
            ->assertSuccessful();

        $this->assertSame(0, TimeClockShift::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, TimeClockDay::query()->withoutGlobalScopes()->count());
    }

    private function bundle(array $records, array $days = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'a1wt');
        file_put_contents($path, json_encode(['time_records' => $records, 'day_statuses' => $days]));

        return $path;
    }

    private function shifts(User $user)
    {
        return TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('clocked_in_at')->get();
    }
}
