<?php

namespace App\Support\TimeClock;

use App\Models\TimeClockShift;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\WhatsAppGroups;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Jose has finished his schedule" (Jose, 2026-11-13): for a role that clocks
 * out by hand (time_clock.manual_clock_out_roles), every finished shift goes
 * on WhatsApp, from the Reports number, to the managers of the person's
 * branch — whoever holds work_hours.approve at the same place.
 *
 * Inside the 24-hour window it is free text; outside it, the approved
 * template time_clock.shift_finished_template with four values, one a line.
 *
 * ponytail: sent here, not as an editable automation rule. A rule needs a
 * "managers of the person's branch" recipient in ActionRunner, which is the
 * Automations module's; add it there when somebody wants to edit the wording.
 */
class ShiftFinishedNotice
{
    public function __construct(
        private readonly TimeClock $clock,
        private readonly SettingsResolver $settings,
        private readonly WhatsAppGroups $whatsApp,
    ) {}

    /** Does this person's role clock out by hand at their place? */
    public function isManual(User $user): bool
    {
        $roles = (array) $this->settings->get('time_clock.manual_clock_out_roles', $this->clock->placeOf($user));

        return $user->role_id !== null && in_array($user->role_id, $roles, true);
    }

    /** @return Collection<int, User> who manages the person's branch */
    public function managersOf(User $person): Collection
    {
        $place = $this->clock->placeOf($person);

        return User::query()->where('is_active', true)->whereKeyNot($person->getKey())->get()
            ->filter(fn (User $u) => $u->hasPermission('work_hours.approve') && $this->clock->placeOf($u)->is($place))
            ->values();
    }

    /** @return array{0: string, 1: list<string>} the message, and the template's four values */
    public function compose(TimeClockShift $shift): array
    {
        $person = $shift->user;
        $place = $this->clock->placeOf($person);
        $tz = (string) $this->settings->get('timezone', $place);
        $format = (string) $this->settings->get('time_format', $place);
        $in = Carbon::parse($shift->clocked_in_at);
        $out = Carbon::parse($shift->clocked_out_at);

        $who = $person->name.(($place->name ?? null) ? ' · '.$place->name : '');
        $outLine = $this->clock->wallClock($out, $tz, $format);
        $window = WorkSchedule::windowFor($this->clock->scheduleFor($person), $in);
        if ($window !== null) {
            $off = (int) Carbon::parse($window['end_at'])->diffInMinutes($out, false);
            if (abs($off) >= 5) {
                $outLine .= ' ('.self::length(abs($off)).($off > 0 ? ' past' : ' before').' schedule)';
            }
        }
        $values = [$who, $this->clock->wallClock($in, $tz, $format), $outLine, self::length((int) $in->diffInMinutes($out, true))];

        $text = "🔔 *Shift finished*\n👷 {$values[0]}\n🟢 In: {$values[1]}\n🔴 Out: {$values[2]}\n⏱️ Total: {$values[3]}\n"
            .'You can review, correct or approve this time in EzBizi.';

        return [$text, $values];
    }

    /** @return int how many managers it went to */
    public function send(TimeClockShift $shift): int
    {
        $number = WhatsAppNumber::forPurpose('reports');
        if ($number === null || $shift->user === null || ! $this->isManual($shift->user)) {
            return 0;
        }
        [$text, $values] = $this->compose($shift);
        $template = (string) $this->settings->get('time_clock.shift_finished_template', $shift->user->organization);
        $sent = 0;
        foreach ($this->managersOf($shift->user) as $manager) {
            try {
                $sent += $this->whatsApp->messageToUser($number, $manager, $text, $template, $values) ? 1 : 0;
            } catch (Throwable $e) {
                report($e);   // one manager's refusal must not cost the others theirs
            }
        }

        return $sent;
    }

    /** 11 h, 1 h 5 m, 25 m */
    private static function length(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return trim(($h > 0 ? "{$h} h " : '').($m > 0 || $h === 0 ? "{$m} m" : ''));
    }
}
