<?php

namespace App\Support\TimeClock;

use App\Mail\WorkHoursReportMail;
use App\Models\TimeClockReport;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailDispatcher;

/**
 * Build and send a saved Work Hours report (2026-09-28).
 *
 * ── Whose hours, and whose eyes ──
 *
 * The rows come from TimeClock::weekList for the person sending — the same
 * call the Work Hours screen makes — so a report can never contain somebody
 * the sender could not open on that screen. A branch manager's report covers
 * their branch whatever roles the definition names. For an automation the
 * sender is the rule's author, as it is for `report.send`.
 *
 * ── Which week ──
 *
 * `last` is the most recent week that has FINISHED, in the organization's own
 * week (Work Hours › Settings › Week starts on) and its own clock. Sent at
 * 8 PM on a Sunday by a Sunday-start organization, that is the Sunday-to-
 * Saturday that ended last night — a complete week, never a partial one.
 * `current` is the week in progress, for somebody checking by hand midweek.
 */
final class TimeClockReports
{
    public const WEEKS = ['last', 'current'];

    public function __construct(
        private readonly TimeClock $clock,
        private readonly MailDispatcher $mail,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{week_start: string, week_end: string, range: string, rows: list<array{id: string, name: string, seconds: int, hours: string, days_worked: int}>, total_seconds: int, total: string}
     */
    public function build(TimeClockReport $report, User $actor, string $week = 'last'): array
    {
        $on = $this->clock->weekStartOf($actor, $week === 'current' ? 0 : -1);
        $list = $this->clock->weekList($actor, $on);

        $roles = collect($report->role_ids ?? [])->map(fn ($id) => (string) $id)->all();
        $in = collect($report->include_user_ids ?? [])->map(fn ($id) => (string) $id)->all();
        $out = collect($report->exclude_user_ids ?? [])->map(fn ($id) => (string) $id)->all();

        $users = User::query()->with('role')->whereIn('id', array_column($list['people'], 'id'))->get()->keyBy('id');
        $roleOf = $users->map(fn (User $u) => (string) $u->role_id);

        $rows = [];
        $total = 0;

        foreach ($list['people'] as $person) {
            $id = (string) $person['id'];

            // No roles and nobody named: everybody the sender can see.
            $selected = ($roles === [] && $in === [])
                || in_array($id, $in, true)
                || in_array($roleOf[$id] ?? '', $roles, true);

            // Nobody with no time: the email is a list of hours, and A1 Tools'
            // leaves them out too. They are on the Work Hours screen.
            // Administrators log no hours (Jose, 2026-11-24), so they are never on it.
            if (! $selected || in_array($id, $out, true) || $person['total_seconds'] <= 0 || $users->get($id)?->isAdministrator()) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'name' => (string) $person['name'],
                'seconds' => (int) $person['total_seconds'],
                'hours' => self::hours((int) $person['total_seconds']),
                'days_worked' => (int) $person['days_worked'],
                'section' => $this->sectionOf($users->get($id), (int) $person['total_seconds']),
            ];
            $total += (int) $person['total_seconds'];
        }

        // Eric Nelson Valdes, 2026-11-25: overtime first and in red, then full
        // time, then part time, each A-Z.
        $order = array_flip(array_keys(self::SECTIONS));
        usort($rows, fn ($a, $b) => [$order[$a['section']], strtolower($a['name'])] <=> [$order[$b['section']], strtolower($b['name'])]);
        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            $in = array_values(array_filter($rows, fn ($r) => $r['section'] === $key));
            if ($in !== []) {
                $sections[] = ['key' => $key, 'label' => $label, 'rows' => $in];
            }
        }

        $days = $list['days'];

        return [
            'week_start' => $list['week_start'],
            'week_end' => $list['week_end'],
            'range' => count($days) >= 2 ? $days[0]['label'].' – '.$days[count($days) - 1]['label'] : $list['week_start'],
            'rows' => $rows,
            'sections' => $sections,
            'total_seconds' => $total,
            'total' => self::hours($total),
        ];
    }

    /**
     * Resolve the report's recipients (or an override) to addresses.
     *
     * `user:<id>` is somebody in the organization and follows their address if
     * it changes; anything else must already be an email address. Unusable
     * entries are dropped rather than failing the send — one stale address
     * must not stop the report reaching everybody else.
     *
     * @param  list<string>|null  $to
     * @return list<string>
     */
    public function recipients(TimeClockReport $report, ?array $to = null): array
    {
        $addresses = [];

        foreach ($to ?? ($report->recipients ?? []) as $entry) {
            $entry = trim((string) $entry);

            if (str_starts_with($entry, 'user:')) {
                $entry = (string) User::query()->whereKey(substr($entry, 5))->value('email');
            }

            if (filter_var($entry, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[] = strtolower($entry);
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Send it. Throws CannotSendMail (the hourly allowance) to the caller, who
     * words it for a person or records it on a rule's run.
     *
     * @param  list<string>|null  $to
     * @return array{to: list<string>, rows: int, range: string}
     */
    public function send(TimeClockReport $report, User $actor, string $week = 'last', ?array $to = null, bool $fromAutomation = false): array
    {
        $addresses = $this->recipients($report, $to);

        if ($addresses === []) {
            throw new \InvalidArgumentException('This report has nobody to send to. Add an email address to it first.');
        }

        $built = $this->build($report, $actor, $week);
        $organization = $actor->organization;

        $this->mail->platformForTenant(
            $organization,
            new WorkHoursReportMail(
                reportName: (string) $report->name,
                range: $built['range'],
                rows: array_map(fn ($row) => ['name' => $row['name'], 'hours' => $row['hours'], 'section' => $row['section']], $built['rows']),
                total: $built['total'],
                businessName: (string) ($organization?->name ?? ''),
                fromAutomation: $fromAutomation,
            ),
            $addresses,
        );

        $this->audit->log('time_clock.report_sent', $report, metadata: [
            'to' => $addresses,
            'week_start' => $built['week_start'],
            'rows' => count($built['rows']),
            'by_automation' => $fromAutomation,
        ], actor: $actor);

        return ['to' => $addresses, 'rows' => count($built['rows']), 'range' => $built['range']];
    }

    public const OVERTIME_SECONDS = 40 * 3600;

    /** Scheduled hours a week from which a person counts as full time (the ACA line). */
    public const FULL_TIME_SCHEDULED_HOURS = 30;

    public const SECTIONS = [
        'overtime' => 'Overtime (over 40 hours)',
        'full_time' => 'Full time',
        'part_time' => 'Part time',
        'unset' => 'Type not set',
    ];

    /**
     * Over 40 hours is overtime, whoever it is. Otherwise full or part time by
     * the hours their schedule gives them a week: HR keeps W-2 or 1099, not
     * full or part time, so the schedule is the only record of it.
     * ponytail: add an HR "Full time / Part time" field when somebody needs
     * it to differ from the schedule.
     */
    private function sectionOf(?User $user, int $seconds): string
    {
        if ($seconds > self::OVERTIME_SECONDS) {
            return 'overtime';
        }
        $days = $user === null ? null : ($this->clock->scheduleFor($user)['days'] ?? null);
        if (! is_array($days)) {
            return 'unset';
        }
        $minutes = 0;
        foreach ($days as $day) {
            if (is_array($day) && isset($day['start'], $day['end'])) {
                [$sh, $sm] = array_map('intval', explode(':', $day['start']));
                [$eh, $em] = array_map('intval', explode(':', $day['end']));
                $minutes += max(0, ($eh * 60 + $em) - ($sh * 60 + $sm));
            }
        }

        return $minutes === 0 ? 'unset' : ($minutes >= self::FULL_TIME_SCHEDULED_HOURS * 60 ? 'full_time' : 'part_time');
    }

    /** "38:05" — hours and minutes, the way A1 Tools' email writes them. */
    public static function hours(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds) + 30, 60);

        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
