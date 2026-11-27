<?php

namespace App\Support\TimeClock;

use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;

/**
 * "Jose has finished his schedule" (Jose, 2026-11-13): a role that clocks out
 * by hand (time_clock.manual_clock_out_roles) is never closed by the schedule,
 * and each finished shift raises time_clock.technician_clocked_out carrying
 * the lines below. Who hears it is an Automations rule (A-1: WhatsApp to
 * `branch_managers:` from the Reports number), so the wording is editable.
 */
class ShiftFinishedNotice
{
    public function __construct(
        private readonly TimeClock $clock,
        private readonly SettingsResolver $settings,
    ) {}

    /** Does this person's role clock out by hand at their place? */
    public function isManual(User $user): bool
    {
        $roles = (array) $this->settings->get('time_clock.manual_clock_out_roles', $this->clock->placeOf($user));

        return $user->role_id !== null && in_array($user->role_id, $roles, true);
    }

    /**
     * The shift in the branch's zone, one fact a field, and all of it as
     * `summary_lines` for a message: "👷 Ana · Ohio", "🟢 In: 7:00 AM",
     * "🔴 Out: 6:00 PM (1 h past schedule)", "⏱️ Total: 11 h".
     *
     * @return array{who: string, branch: ?string, clocked_in: string, clocked_out: string, total: string, summary_lines: string}
     */
    public function compose(TimeClockShift $shift): array
    {
        $person = $shift->user;
        $place = $this->clock->placeOf($person);
        $tz = (string) $this->settings->get('timezone', $place);
        $format = (string) $this->settings->get('time_format', $place);
        $in = Carbon::parse($shift->clocked_in_at);
        $out = Carbon::parse($shift->clocked_out_at);

        $branch = $place->getAttribute('name');
        $who = $person->name.($branch ? " · {$branch}" : '');
        $outLine = $this->clock->wallClock($out, $tz, $format);
        $window = WorkSchedule::windowFor($this->clock->scheduleFor($person), $in);
        if ($window !== null) {
            $off = (int) Carbon::parse($window['end_at'])->diffInMinutes($out, false);
            if (abs($off) >= 5) {
                $outLine .= ' ('.self::length(abs($off)).($off > 0 ? ' past' : ' before').' schedule)';
            }
        }
        $inLine = $this->clock->wallClock($in, $tz, $format);
        $total = self::length((int) $in->diffInMinutes($out, true));

        return [
            'who' => $who,
            'branch' => $branch,
            'clocked_in' => $inLine,
            'clocked_out' => $outLine,
            'total' => $total,
            'summary_lines' => "👷 {$who}\n🟢 In: {$inLine}\n🔴 Out: {$outLine}\n⏱️ Total: {$total}\n"
                .'You can review, correct or approve this time in EzBizi.',
        ];
    }

    /** 11 h, 1 h 5 m, 25 m */
    private static function length(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? "{$h} h " : '').($m > 0 || $h === 0 ? "{$m} m" : ''));
    }
}
