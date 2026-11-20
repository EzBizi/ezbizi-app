<?php

namespace Tests\Feature\Api;

use App\Console\Commands\FlagWorkizJobs;
use App\Console\Commands\SchedulingFlagFullWindows;
use App\Http\Controllers\Api\TasksController;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Jobs\JobAddress;
use App\Support\Settings\SettingsResolver;
use App\Support\Tasks\TaskBoards;
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

        $this->artisan('ezbizi:flag-workiz-jobs --dry-run')->expectsOutputToContain('3 flags would be raised')->assertSuccessful();
        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('3 flags raised')->assertSuccessful();
        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('0 flags raised')->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($booker) {
            $titles = Notification::query()->where('user_id', $booker->id)->pluck('title')->sort()->values()->all();
            $this->assertSame([
                'Job #101: Address needs fixing (no house number): "Savin Ave, 06516"',
                'Job #102: Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.',
                'Job #104: Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.',
            ], $titles);
            $task = Task::query()->where('title', 'like', 'Address needs fixing%')->firstOrFail();
            $this->assertSame(['job', true], [$task->about_type, $task->about_id !== null]);
            $this->assertSame(3, Task::query()->whereNull('created_by_user_id')->count());
            // Desk 11-18: owned by the booker it alerts, made by EzBizi, and says what to do.
            $this->assertSame([$booker->id], $task->owners()->pluck('user_id')->all());
            // Row 6 (desk 11-20): a short title; what to do lives in Notes.
            $this->assertStringNotContainsString(' · ', $task->title);
            $this->assertLessThanOrEqual(121, mb_strlen($task->title));
            $this->assertStringEndsWith(' · Fix the address in Workiz so the tech can find it', (string) $task->values()->whereHas('column', fn ($q) => $q->where('name', 'Notes'))->value('value_text'));
            // Jose 11-18: a job's task carries both job ids as columns.
            $ids = TaskColumnValue::query()->with('column')->where('task_id', $task->id)->get()->mapWithKeys(fn ($v) => [$v->column->name => $v->value_text])->all();
            $this->assertSame(['Workiz Job ID' => '101', 'EzBizi Job #' => 'J-1'], array_intersect_key($ids, array_flip(['Workiz Job ID', 'EzBizi Job #'])));
            // No EzBizi job: the Workiz number still lands in its column, not the title (Jose 11-18).
            $late = Task::query()->where('title', 'like', 'Friday jobs%')->get()->map(fn ($t) => $t->values()->with('column')->get()->firstWhere('column.name', 'Workiz Job ID')?->value_text)->sort()->values()->all();
            $this->assertSame(['102', '104'], $late);
            $this->assertSame(['type' => 'automation', 'label' => 'EzBizi'], TasksController::present($task)['created_by']);
        });
    }

    /** Desk 11-18: a flag task never has no owner: booker, else field managers, else the General Manager. */
    public function test_a_flag_task_falls_back_to_the_field_managers_then_the_general_manager(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $gm = $this->userWithRole($alpha, 'GM', ['tasks.view'], 'shneor@ezbizi.test');
        $fm = $this->userWithRole($alpha, 'FM', ['tasks.view'], 'fm@ezbizi.test');
        $this->inTenant($alpha['org'], function () use ($alpha, $gm, $fm) {
            $gm->forceFill(['name' => 'Shneor Cohen'])->save();
            $board = app(TaskBoards::class)->ensureFor($alpha['franchise']);
            TaskGroup::create(['organization_id' => $alpha['org']->id, 'board_id' => $board->id, 'name' => 'General Manager - Shneor', 'position' => 9]);
            $can = [(string) $gm->id, (string) $fm->id];

            $this->assertSame([(string) $gm->id], FlagWorkizJobs::owners($board, $alpha['franchise'], ['not-on-board'], $can), 'no field managers listed: the GM');
            app(SettingsResolver::class)->set(SchedulingFlagFullWindows::RECIPIENTS, $alpha['franchise'], 'fm@ezbizi.test');
            $this->assertSame([(string) $fm->id], FlagWorkizJobs::owners($board, $alpha['franchise'], ['not-on-board'], $can), 'the listed field manager');
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

    public function test_the_backfill_takes_only_upcoming_open_jobs(): void
    {
        Carbon::setTestNow('2026-11-17 10:00:00');
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $mk = fn (string $id, string $status, string $at) => WorkizJob::create(['franchise_id' => $alpha['franchise']->id, 'remote_id' => $id,
                'status' => $status, 'address' => 'Savin Ave', 'postal_code' => '06516', 'scheduled_for' => $at, 'payload' => [], 'synced_at' => now()]);
            $mk('AHEAD', 'Submitted', '2026-11-18 09:00:00');
            $mk('PAST', 'Submitted', '2026-11-10 09:00:00');
            $mk('SHUT', 'Canceled', '2026-11-18 09:00:00');
            $mk('DONE', 'Done', '2026-11-18 09:00:00');
        });
        // Old rows: the 20-minute sweep would not see them.
        WorkizJob::query()->withoutGlobalScopes()->update(['updated_at' => now()->subDays(10)]);

        $this->artisan('ezbizi:flag-workiz-jobs')->expectsOutputToContain('0 flags raised')->assertSuccessful();
        $this->artisan('ezbizi:flag-workiz-jobs --future-only --dry-run')->expectsOutputToContain('1 flag would be raised')->assertSuccessful();
        $this->artisan('ezbizi:flag-workiz-jobs --future-only')->expectsOutputToContain('1 flag raised')->assertSuccessful();
    }

    /** Jose 11-19: Workiz "Sasha Solano" is the user "Sasha marie Solano"; an ambiguous match is nobody. */
    public function test_a_booker_matches_a_user_with_a_middle_name(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $sasha = $this->userWithRole($alpha, 'Dispatcher', ['jobs.view']);
        $this->inTenant($alpha['org'], function () use ($alpha, $sasha) {
            $sasha->forceFill(['name' => 'Sasha marie Solano'])->save();
            $w = WorkizJob::create(['franchise_id' => $alpha['franchise']->id, 'remote_id' => 'S1', 'status' => 'x',
                'created_by' => 'Sasha Solano', 'payload' => [], 'synced_at' => now()]);
            $this->assertSame($sasha->id, FlagWorkizJobs::booker($w)?->id);
            $this->assertNull(FlagWorkizJobs::byFirstAndLast('Sasha Perez'));
            $this->assertNull(FlagWorkizJobs::byFirstAndLast('Sasha'));
        });
    }

    /** Row 6 (desk 11-20): a flag title over 120 is cut on a word, like a rule's. */
    public function test_a_long_flag_title_is_cut_on_a_word(): void
    {
        $long = 'Address needs fixing (no house number): "'.str_repeat('Valley Lane ', 15).'"';
        $short = FlagWorkizJobs::shortTitle($long);
        $this->assertLessThanOrEqual(121, mb_strlen($short));
        $this->assertStringEndsWith('…', $short);
        $this->assertStringStartsWith('Address needs fixing (no house number)', $short);
        $this->assertSame('Short one', FlagWorkizJobs::shortTitle('Short one'));
    }
}
