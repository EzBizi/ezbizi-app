<?php

namespace Tests\Feature\TimeClock;

use App\Mail\WorkHoursReportMail;
use App\Models\AuditEvent;
use App\Models\Automation;
use App\Models\AutomationVersion;
use App\Models\Organization;
use App\Models\TimeClockReport;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The weekly hours report (Jose, 2026-09-28): "sends the Total works hour for
 * the week … weekly on sundays at 8 pm (miami time) … possible to do manually
 * as well … choose which roles, or which specific person to add".
 *
 * Every case runs at the moment the rule will: Sunday 27 September 2026, 8 PM
 * in New York (Miami's zone), which is 00:00 UTC on the 28th.
 */
class TimeClockReportsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-28 00:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * "Last week" at 8 PM on a Sunday is the Sunday-to-Saturday that ended
     * last night — complete, never the few hours of today.
     */
    public function test_last_week_is_the_week_that_just_finished(): void
    {
        [$alpha, $manager, $techs] = $this->tenant();
        $this->shift($alpha['org'], $techs['ana'], '2026-09-21 12:00:00', '2026-09-21 20:30:00'); // Mon, 8:30
        $this->shift($alpha['org'], $techs['ana'], '2026-09-27 14:00:00', '2026-09-27 15:00:00'); // today: NOT in it
        $this->shift($alpha['org'], $techs['ana'], '2026-09-19 14:00:00', '2026-09-19 15:00:00'); // the week before: NOT in it

        $report = $this->report($alpha['org'], []);

        $data = $this->signedInAs($manager)->getJson("/api/v1/hr/time-clock/reports/{$report->id}/preview")->assertOk()->json('data');

        $this->assertSame('2026-09-20', $data['week_start']);
        $this->assertSame('2026-09-26', $data['week_end']);
        $this->assertSame([['Ana', '8:30']], array_map(fn ($r) => [$r['name'], $r['hours']], $data['rows']));
    }

    /**
     * Eric Nelson Valdes, 2026-11-25: overtime (over 40 h) first, then full
     * time, then part time, each A-Z; full or part time by the hours the
     * schedule gives them a week. The email carries the same sections.
     */
    public function test_the_report_is_overtime_then_full_time_then_part_time_each_a_to_z(): void
    {
        [$alpha, $manager, $techs] = $this->tenant();
        $week = fn (string $end) => ['days' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['start' => '09:00', 'end' => $end])];
        foreach (['ana' => '17:00', 'ben' => '17:00', 'eve' => '17:00', 'cy' => '13:00', 'dee' => '13:00'] as $name => $end) {
            $this->inTenant($alpha['org'], fn () => $techs[$name]->forceFill(['work_schedule' => $week($end)])->save());   // 40 h or 20 h a week
        }
        foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $d) {
            $this->shift($alpha['org'], $techs['eve'], "{$d} 12:00:00", "{$d} 21:00:00");   // 45 h: overtime
            $this->shift($alpha['org'], $techs['ben'], "{$d} 12:00:00", "{$d} 18:00:00");   // 30 h
            $this->shift($alpha['org'], $techs['dee'], "{$d} 12:00:00", "{$d} 15:00:00");   // 15 h, part time
        }
        $this->shift($alpha['org'], $techs['ana'], '2026-09-21 12:00:00', '2026-09-21 20:00:00');
        $this->shift($alpha['org'], $techs['cy'], '2026-09-21 12:00:00', '2026-09-21 14:00:00');
        $report = $this->report($alpha['org'], []);

        $data = $this->signedInAs($manager)->getJson("/api/v1/hr/time-clock/reports/{$report->id}/preview")->assertOk()->json('data');

        $this->assertSame(
            ['overtime' => ['Eve'], 'full_time' => ['Ana', 'Ben'], 'part_time' => ['Cy', 'Dee']],
            collect($data['sections'])->mapWithKeys(fn ($s) => [$s['key'] => array_column($s['rows'], 'name')])->all(),
        );
        $this->assertSame(['Eve', 'Ana', 'Ben', 'Cy', 'Dee'], array_column($data['rows'], 'name'));

        $html = (new WorkHoursReportMail('Weekly hours', $data['range'], array_map(
            fn ($r) => ['name' => $r['name'], 'hours' => $r['hours'], 'section' => $r['section']], $data['rows']), $data['total']))->render();
        $this->assertMatchesRegularExpression('/Overtime.*c62828[^>]*>Eve.*Full time.*Ana.*Ben.*Part time.*Cy.*Dee/s', $html);
    }

    /** Roles select, a named person is added, a named person is left out, and nobody with no time appears. */
    public function test_roles_and_named_people_decide_who_is_in_it(): void
    {
        [$alpha, $manager, $techs] = $this->tenant();
        foreach (['ana', 'ben', 'cy', 'dee'] as $name) {
            $this->shift($alpha['org'], $techs[$name], '2026-09-22 12:00:00', '2026-09-22 16:00:00');
        }

        // Technicians (ana, ben), plus cy by name, minus ben by name. dee is
        // an office role and not named. eve is a technician with no hours.
        $report = $this->report($alpha['org'], [
            'role_ids' => [$techs['ana']->role_id],
            'include_user_ids' => [$techs['cy']->id],
            'exclude_user_ids' => [$techs['ben']->id],
        ]);

        $rows = $this->signedInAs($manager)->getJson("/api/v1/hr/time-clock/reports/{$report->id}/preview")->assertOk()->json('data.rows');

        $this->assertSame(['Ana', 'Cy'], array_column($rows, 'name'));
    }

    /**
     * A branch manager's report is their branch's, whatever roles it names —
     * the rows come from the same call the Work Hours screen makes for them.
     */
    public function test_a_report_never_reaches_past_its_senders_branch(): void
    {
        [$alpha, , $techs] = $this->tenant();
        $elsewhere = $this->extraFranchise($alpha, 'Alpha F2');
        $theirs = $this->userWithRole($alpha, 'Far', ['time_clock.view'], 'far@alpha.test', onlyAt: $elsewhere);
        $mine = $this->userWithRole($alpha, 'Near', ['time_clock.view'], 'near@alpha.test', onlyAt: $alpha['franchise']);
        $branch = $this->userWithRole($alpha, 'Branch', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage', 'work_hours.reports.view'], 'branch@alpha.test', onlyAt: $alpha['franchise']);

        foreach ([$theirs, $mine] as $person) {
            $this->shift($alpha['org'], $person, '2026-09-22 12:00:00', '2026-09-22 16:00:00');
        }

        $report = $this->report($alpha['org'], []);

        $ids = array_column(
            $this->signedInAs($branch)->getJson("/api/v1/hr/time-clock/reports/{$report->id}/preview")->assertOk()->json('data.rows'),
            'id'
        );

        $this->assertContains($mine->id, $ids, 'the control: their own branch is in it');
        $this->assertNotContains($theirs->id, $ids);
        unset($techs);
    }

    public function test_send_now_emails_the_table_to_every_recipient_and_records_it(): void
    {
        Mail::fake();
        [$alpha, $manager, $techs] = $this->tenant();
        $this->shift($alpha['org'], $techs['ana'], '2026-09-21 12:00:00', '2026-09-21 20:00:00');

        $report = $this->report($alpha['org'], [
            'name' => 'Weekly hours',
            'recipients' => ['dismanager@a-1chimney.com', "user:{$manager->id}", 'not-an-address'],
        ]);

        $sent = $this->signedInAs($manager)->postJson("/api/v1/hr/time-clock/reports/{$report->id}/send")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['dismanager@a-1chimney.com', strtolower($manager->email)], $sent['to']);

        Mail::assertSent(WorkHoursReportMail::class, function (WorkHoursReportMail $mail) {
            return $mail->reportName === 'Weekly hours'
                && array_map(fn ($r) => [$r['name'], $r['hours']], $mail->rows) === [['Ana', '8:00']]
                && $mail->total === '8:00'
                && str_contains($mail->envelope()->subject, 'Weekly hours');
        });

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.report_sent')->count());
    }

    public function test_a_report_with_nobody_to_send_to_says_so(): void
    {
        Mail::fake();
        [$alpha, $manager] = $this->tenant();
        $report = $this->report($alpha['org'], ['recipients' => []]);

        $this->signedInAs($manager)->postJson("/api/v1/hr/time-clock/reports/{$report->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('errors.send.0', 'This report has nobody to send to. Add an email address to it first.');

        Mail::assertNothingSent();
    }

    /** The tab reads; only `manage` saves or sends. The reader is shown to reach the tab first. */
    public function test_the_reports_tab_alone_cannot_save_or_send(): void
    {
        Mail::fake();
        [$alpha] = $this->tenant();
        $reader = $this->userWithRole($alpha, 'Reader', ['work_hours.general.view', 'work_hours.all', 'work_hours.reports.view'], 'reader@alpha.test');
        $report = $this->report($alpha['org'], []);

        $this->signedInAs($reader)->getJson('/api/v1/hr/time-clock/reports')->assertOk();
        $this->signedInAs($reader)->getJson("/api/v1/hr/time-clock/reports/{$report->id}/preview")->assertOk();

        $this->signedInAs($reader)->postJson("/api/v1/hr/time-clock/reports/{$report->id}/send")->assertForbidden();
        $this->signedInAs($reader)->postJson('/api/v1/hr/time-clock/reports', ['name' => 'Mine'])->assertForbidden();

        Mail::assertNothingSent();
    }

    /**
     * The list says which rules send each report; rule names and schedules
     * only to a reader who holds automations.view. `sent_automatically` means
     * "will this go out?", as on Reports' list: a report whose only rule is
     * switched off reads false, though the rule is still named. The control
     * is a report no rule sends.
     */
    public function test_the_list_says_which_rules_send_a_report_to_readers_of_automations(): void
    {
        [$alpha, $manager] = $this->tenant();
        $sent = $this->report($alpha['org'], ['name' => 'A sent']);
        $this->report($alpha['org'], ['name' => 'B by hand']);
        $paused = $this->report($alpha['org'], ['name' => 'C paused']);

        $rule = fn (TimeClockReport $report, string $name, bool $active) => $this->inTenant($alpha['org'], function () use ($alpha, $report, $name, $active) {
            $rule = Automation::create(['organization_id' => $alpha['org']->id, 'name' => $name,
                'trigger_key' => 'automation.schedule_reached', 'is_active' => $active,
                'schedule' => ['kind' => 'weekly', 'days' => ['sunday'], 'time' => '20:00', 'timezone' => 'America/New_York']]);
            $v = AutomationVersion::create(['organization_id' => $alpha['org']->id, 'automation_id' => $rule->id, 'version' => 1,
                'conditions' => null, 'actions' => [['type' => 'send_report', 'report_id' => $report->id]], 'created_at' => now()]);
            $rule->forceFill(['active_version_id' => $v->id])->save();

            return $rule;
        });
        $sunday = $rule($sent, 'Sunday hours', true);
        $off = $rule($paused, 'Switched off', false);

        $plain = $this->signedInAs($manager)->getJson('/api/v1/hr/time-clock/reports')->assertOk();
        $this->assertSame([true, false, false], array_column($plain->json('data'), 'sent_automatically'));
        $this->assertSame([null, null, null], array_column($plain->json('data'), 'sent_by'), 'no rule names without automations.view');

        $both = $this->userWithRole($alpha, 'Ops', ['work_hours.general.view', 'work_hours.reports.view', 'automations.view'], 'ops@alpha.test');
        $full = $this->signedInAs($both)->getJson('/api/v1/hr/time-clock/reports')->assertOk();
        $this->assertSame($sunday->id, $full->json('data.0.sent_by.0.id'));
        $this->assertSame('Sunday hours', $full->json('data.0.sent_by.0.name'));
        $this->assertArrayHasKey('disabled_at', $full->json('data.0.sent_by.0'));
        $this->assertSame([], $full->json('data.1.sent_by'));
        $this->assertSame($off->id, $full->json('data.2.sent_by.0.id'), 'the off rule is still named');
        $this->assertFalse($full->json('data.2.sent_by.0.is_active'));
    }

    public function test_saving_refuses_a_person_outside_reach_and_a_bad_address(): void
    {
        [$alpha, , $techs] = $this->tenant();
        $elsewhere = $this->extraFranchise($alpha, 'Alpha F2');
        $theirs = $this->userWithRole($alpha, 'Far', ['time_clock.view'], 'far@alpha.test', onlyAt: $elsewhere);
        $branch = $this->userWithRole($alpha, 'Branch', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage', 'work_hours.reports.view'], 'branch@alpha.test', onlyAt: $alpha['franchise']);

        $this->signedInAs($branch)->postJson('/api/v1/hr/time-clock/reports', ['name' => 'X', 'include_user_ids' => [$theirs->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.include_user_ids.0', 'One of those people is not somebody you can see on Work Hours.');

        $this->signedInAs($branch)->postJson('/api/v1/hr/time-clock/reports', ['name' => 'X', 'recipients' => ['nope']])
            ->assertStatus(422);

        $this->signedInAs($branch)->postJson('/api/v1/hr/time-clock/reports', ['name' => 'Weekly', 'recipients' => ['a@b.test']])
            ->assertCreated();
        unset($techs);
    }

    /**
     * @return array{0: array, 1: User, 2: array<string, User>}
     */
    private function tenant(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], function () use ($alpha) {
            app(SettingsResolver::class)->set('time_clock.enabled', $alpha['org'], true);
            app(SettingsResolver::class)->set('timezone', $alpha['org'], 'America/New_York');
        });

        $manager = $this->userWithRole($alpha, 'Manager', [
            'work_hours.general.view', 'work_hours.all', 'work_hours.manage', 'work_hours.reports.view',
        ], 'manager@alpha.test');

        $techs = [];
        $techRole = null;
        foreach (['ana', 'ben', 'eve'] as $name) {
            $techs[$name] = $this->userWithRole($alpha, "Technician {$name}", ['time_clock.view'], "{$name}@alpha.test");
            $techRole ??= $techs[$name]->role_id;
            $this->inTenant($alpha['org'], fn () => $techs[$name]->forceFill(['role_id' => $techRole, 'name' => ucfirst($name)])->save());
        }
        foreach (['cy', 'dee'] as $name) {
            $techs[$name] = $this->userWithRole($alpha, 'Office '.$name, ['time_clock.view'], "{$name}@alpha.test");
            $this->inTenant($alpha['org'], fn () => $techs[$name]->forceFill(['name' => ucfirst($name)])->save());
        }

        return [$alpha, $manager, array_map(fn ($u) => $u->fresh(), $techs)];
    }

    private function report(Organization $org, array $attributes): TimeClockReport
    {
        return $this->inTenant($org, fn () => TimeClockReport::query()->create([
            'organization_id' => $org->id,
            'name' => 'Weekly hours',
            'recipients' => ['dismanager@a-1chimney.com'],
            ...$attributes,
        ]));
    }

    private function shift(Organization $org, User $user, string $in, string $out): void
    {
        $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
        ]));
    }
}
