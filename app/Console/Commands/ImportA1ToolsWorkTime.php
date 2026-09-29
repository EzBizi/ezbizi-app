<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TimeClockDay;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A1 Tools clock-ins and day statuses into Work Hours
 * (docs/A1-TOOLS-PEOPLE-MIGRATION.md, "Who builds what", item 2).
 *
 * Runs AFTER `hr:import-a1-tools`: people are matched by email to the users
 * that importer made, and anybody with no EzBizi account — former staff,
 * whom Jose chose not to migrate — is counted and skipped.
 *
 * ── The rules, each for a reason ──
 *
 *   * EZBIZI WINS AN OVERLAP. Ten of the twelve people already in EzBizi have
 *     clocked in to both systems since 2026-08-25, often seconds apart. The
 *     EzBizi row is the one the person, the sweep and any correction have
 *     already touched; the A1 row for the same hours is skipped, never merged.
 *     Two A1 rows that overlap each other keep the earlier.
 *   * AT MOST ONE OPEN SHIFT, AND NEVER ONE WITHOUT AN END. A1 still has
 *     people clocked in when this runs. An open A1 shift is imported open,
 *     with the organization's cap as its end, so they can clock out in EzBizi
 *     and the sweep can see it — a null end is invisible to the sweep, the
 *     defect that stranded five people on 2026-09-23. One that started longer
 *     ago than the cap is imported closed at the cap: a forgotten punch.
 *   * A DAY WITH HOURS IS NOT ABSENT. A1's cron wrote `absent` for days it saw
 *     no punch, and some of those rows were blanked values an old A1
 *     migration turned into `absent`. It is imported only onto a day with no
 *     shift and no tag already. `other` has no EzBizi tag and is skipped.
 *   * RE-RUNNABLE. Every imported shift carries `import_ref` (a1tools:<id>),
 *     so a second run — the cut-over, for shifts that were still open —
 *     writes only what is new.
 *
 * Output is counts and EzBizi ids only. No name, email or time is printed:
 * the migration's rule is that no personal value reaches a log.
 */
class ImportA1ToolsWorkTime extends Command
{
    protected $signature = 'worktime:import-a1-tools
        {file : JSON bundle with time_records and day_statuses}
        {--organization=A1-Group : Organization name}
        {--per-person : Print counts per EzBizi user id}
        {--dry-run : Report what would be written and write nothing}';

    protected $description = 'Import A1 Tools clock-ins and day statuses into Work Hours';

    public function handle(TenantContext $tenant, SettingsResolver $settings, AuditLogger $audit): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $bundle = json_decode((string) file_get_contents($path), true);
        if (! is_array($bundle) || ! is_array($bundle['time_records'] ?? null)) {
            $this->error('That file is not a JSON object with a time_records list (see the spec, "Clock In export format").');

            return self::FAILURE;
        }

        $organization = Organization::query()->where('name', $this->option('organization'))->first();
        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        $tenant->set($organization);
        $capHours = (int) $settings->get('time_clock.max_hours', $organization);
        $dry = (bool) $this->option('dry-run');

        $people = User::query()->get(['id', 'email'])
            ->keyBy(fn (User $u) => strtolower(trim((string) $u->email)));

        $counts = array_fill_keys([
            'shifts_read', 'shifts_imported', 'shifts_imported_open', 'shifts_closed_at_cap',
            'shifts_already_imported', 'shifts_overlap_ezbizi', 'shifts_overlap_a1', 'shifts_second_open',
            'shifts_no_person', 'shifts_unreadable',
            'days_read', 'days_tagged', 'days_other', 'days_has_hours', 'days_already_said', 'days_no_person',
        ], 0);
        $perPerson = [];

        DB::beginTransaction();

        try {
            // Oldest first, so "keep the earlier" is literally the order written.
            $records = collect($bundle['time_records'])
                ->filter(fn ($r) => is_array($r))
                ->sortBy(fn ($r) => (string) ($r['clock_in'] ?? ''))
                ->values();

            foreach ($records as $record) {
                $counts['shifts_read']++;
                $ref = 'a1tools:'.($record['id'] ?? '');
                $person = $people[strtolower(trim((string) ($record['email'] ?? '')))] ?? null;

                if ($person === null) {
                    $counts['shifts_no_person']++;

                    continue;
                }

                $in = $this->instant($record['clock_in'] ?? null);
                $out = $this->instant($record['clock_out'] ?? null);

                if ($in === null || ($record['id'] ?? null) === null || ($out !== null && $out->lte($in))) {
                    $counts['shifts_unreadable']++;

                    continue;
                }

                if (TimeClockShift::query()->where('import_ref', $ref)->exists()) {
                    $counts['shifts_already_imported']++;

                    continue;
                }

                $now = now();
                $closedAtCap = false;
                if ($out === null && $in->copy()->addHours($capHours)->lte($now)) {
                    $out = $in->copy()->addHours($capHours);
                    $closedAtCap = true;
                }

                // Any shift of theirs — EzBizi's own or imported earlier in
                // this run — that shares a moment with this one.
                $clash = TimeClockShift::query()
                    ->where('user_id', $person->id)
                    ->where('clocked_in_at', '<', $out ?? $now->copy()->addCentury())
                    ->where(fn ($q) => $q->whereNull('clocked_out_at')->orWhere('clocked_out_at', '>', $in))
                    ->orderByRaw('import_ref is null desc')
                    ->first();

                if ($clash !== null) {
                    $counts[$clash->import_ref === null ? 'shifts_overlap_ezbizi' : 'shifts_overlap_a1']++;

                    continue;
                }

                if ($out === null && TimeClockShift::query()->open()->where('user_id', $person->id)->exists()) {
                    $counts['shifts_second_open']++;

                    continue;
                }

                TimeClockShift::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $person->id,
                    'clocked_in_at' => $in,
                    'clocked_out_at' => $out,
                    // Never null on an open row: the sweep selects whereNotNull.
                    'scheduled_end_at' => $out === null ? $in->copy()->addHours($capHours) : null,
                    'ended_by' => $out === null ? null : ($closedAtCap ? TimeClockShift::ENDED_BY_SCHEDULE : TimeClockShift::ENDED_BY_PERSON),
                    'outside_schedule' => false,
                    'extensions' => 0,
                    'import_ref' => $ref,
                ]);

                $counts['shifts_imported']++;
                if ($out === null) {
                    $counts['shifts_imported_open']++;
                }
                if ($closedAtCap) {
                    $counts['shifts_closed_at_cap']++;
                }
                $perPerson[$person->id]['shifts'] = ($perPerson[$person->id]['shifts'] ?? 0) + 1;
            }

            $tz = (string) $settings->get('timezone', $organization);

            foreach ((array) ($bundle['day_statuses'] ?? []) as $status) {
                if (! is_array($status)) {
                    continue;
                }
                $counts['days_read']++;
                $person = $people[strtolower(trim((string) ($status['email'] ?? '')))] ?? null;
                $date = (string) ($status['date'] ?? '');

                if ($person === null) {
                    $counts['days_no_person']++;

                    continue;
                }

                if (($status['status'] ?? null) !== 'absent' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    $counts['days_other']++;

                    continue;
                }

                $dayStart = Carbon::parse($date, $tz)->startOfDay()->utc();
                $hasHours = TimeClockShift::query()
                    ->where('user_id', $person->id)
                    ->where('clocked_in_at', '>=', $dayStart)
                    ->where('clocked_in_at', '<', $dayStart->copy()->addDay())
                    ->exists();

                if ($hasHours) {
                    $counts['days_has_hours']++;

                    continue;
                }

                $existing = TimeClockDay::query()->where('user_id', $person->id)->whereDate('date', $date)->first();
                if ($existing !== null && ($existing->tag !== null || $existing->note !== null || $existing->extra_minutes > 0)) {
                    $counts['days_already_said']++;

                    continue;
                }

                TimeClockDay::query()->updateOrCreate(
                    ['user_id' => $person->id, 'date' => $date],
                    ['organization_id' => $organization->id, 'tag' => 'absent', 'extra_minutes' => 0],
                );
                $counts['days_tagged']++;
                $perPerson[$person->id]['absent'] = ($perPerson[$person->id]['absent'] ?? 0) + 1;
            }

            if ($dry) {
                DB::rollBack();
            } else {
                $audit->log('time_clock.imported', $organization, metadata: ['source' => 'a1tools', 'counts' => $counts]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line(($dry ? 'DRY RUN — nothing written. ' : '').'Organization '.$organization->id);
        foreach ($counts as $key => $value) {
            $this->line("{$key}: {$value}");
        }

        if ($this->option('per-person')) {
            foreach ($perPerson as $id => $row) {
                $this->line($id.'  shifts='.($row['shifts'] ?? 0).'  absent='.($row['absent'] ?? 0));
            }
        }

        return self::SUCCESS;
    }

    /** A1 stores UTC; parse it AS UTC, whatever the app's zone. */
    private function instant(mixed $value): ?Carbon
    {
        $text = trim((string) $value);
        if ($text === '' || str_starts_with($text, '0000-')) {
            return null;
        }

        try {
            return Carbon::parse($text, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
