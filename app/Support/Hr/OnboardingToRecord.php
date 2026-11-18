<?php

namespace App\Support\Hr;

use App\Models\HeldEquipment;
use App\Models\Onboarding;
use App\Models\PayRate;
use App\Models\User;
use App\Support\Contracts\RateSteps;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A signed contract's answers, written into the person's record when the last
 * signature lands (Jose, 2026-09-30: "of course", to the contract's pay, start
 * date and schedule filling the record). A1 Tools did the same on completion:
 * payroll from the deal terms, equipment from the checklists.
 *
 * Read by FIELD KEY, the keys the onboarding catalogue's FieldLibrary gives
 * every template: a tenant's own contract that names them fills the record
 * too, and one that does not fills nothing. Each part only fills a gap, or
 * adds a row that is not there yet, so it never overwrites what HR typed.
 * Anything it cannot read for certain (a salary "every two weeks", work hours
 * written as prose) is left empty, and Records shows it amber.
 */
final class OnboardingToRecord
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(private readonly SettingsResolver $settings) {}

    /** @return list<string> what was written, named for the audit line, never valued */
    public function apply(Onboarding $onboarding): array
    {
        $user = $onboarding->user;
        if ($user === null) {
            return [];
        }

        // Through the model: `value` is an encrypted cast, which pluck skips.
        $a = $onboarding->answers()->get()->mapWithKeys(
            fn ($answer) => [$answer->field_key => trim((string) $answer->value)],
        )->all();

        $written = [];
        $hired = self::date($a['date_of_hire'] ?? '');

        if ($hired !== null && StartDate::record($user, $hired)['changed']) {
            $written[] = 'start_date';
        }

        if (blank($user->address) && filled($a['address'] ?? null)) {
            $user->forceFill(['address' => $a['address']])->save();
            $written[] = 'address';
        }

        $on = $hired ?? now()->toDateString();

        if (($rate = self::payRate($a)) !== null && ! PayRate::query()->where('user_id', $user->id)
            ->where('basis', $rate['basis'])->where('period', $rate['period'])
            ->whereDate('effective_on', $on)->exists()) {
            PayRate::create($rate + [
                'user_id' => $user->id,
                'effective_on' => $on,
                'note' => 'From the signed contract',
            ]);
            $written[] = 'pay_rate';
        }

        // Hourly rate stages (Merav, 2026-11-17): each one a pay rate dated N
        // days after the start, so it applies itself on that day and the
        // pay_rates rows are the history. Needs the real start date.
        [$steps] = RateSteps::parse($a['hourly_rate_steps'] ?? '[]');
        foreach ($hired === null ? [] : ($steps ?? []) as $step) {
            $from = Carbon::parse($hired)->addDays($step['after_days'])->toDateString();
            if (! PayRate::query()->where('user_id', $user->id)->where('basis', 'hourly')->whereDate('effective_on', $from)->exists()) {
                PayRate::create([
                    'user_id' => $user->id, 'basis' => 'hourly', 'amount' => $step['rate'], 'period' => 'hour',
                    'effective_on' => $from, 'note' => "Rate stage: after {$step['after_days']} days",
                ]);
                $written[] = 'pay_rate_stages';
            }
        }

        foreach (['tools' => 'tool', 'equipment' => 'equipment'] as $key => $category) {
            foreach (json_decode($a[$key] ?? '[]', true) ?: [] as $row) {
                $name = trim((string) ($row['item'] ?? ''));
                if ($name === '' || HeldEquipment::query()->where('user_id', $user->id)
                    ->where('name', $name)->whereNull('returned_on')->exists()) {
                    continue;
                }
                HeldEquipment::create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'category' => $category,
                    'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
                    'issued_on' => self::date($row['received'] ?? '') ?? $on,
                ]);
                $written[] = $key;
            }
        }

        if ($user->work_schedule === null && $this->writeSchedule($user, $a)) {
            $written[] = 'work_schedule';
        }

        return array_values(array_unique($written));
    }

    /**
     * The contract's work days and hours into the person's schedule, the
     * moment they are answered (Jose, 2026-10-01: "that gets already saved to
     * their profile and hr"). True when it could be read and was written.
     */
    public function saveSchedule(Onboarding $onboarding): bool
    {
        $user = $onboarding->user;
        if ($user === null) {
            return false;
        }

        $a = $onboarding->answers()->whereIn('field_key', ['work_days', 'work_hours'])->get()
            ->mapWithKeys(fn ($answer) => [$answer->field_key => trim((string) $answer->value)])->all();

        return $this->writeSchedule($user, $a);
    }

    private function writeSchedule(User $user, array $a): bool
    {
        $days = self::schedule($a['work_days'] ?? '', $a['work_hours'] ?? '');
        if ($days === null) {
            return false;
        }

        $zone = (string) $this->settings->get('timezone', ViewScope::forUser($user)->settingsScope());
        $user->forceFill(['work_schedule' => ['timezone' => $zone, 'days' => $days]])->save();

        return true;
    }

    /** @return array{basis: string, amount: string, period: string}|null */
    public static function payRate(array $a): ?array
    {
        $basis = strtolower($a['pay_basis'] ?? '');
        $basis = in_array($basis, PayRate::BASES, true) ? $basis
            : (filled($a['hourly_rate'] ?? null) ? 'hourly' : (filled($a['salary_amount'] ?? null) ? 'salary' : null));

        if ($basis === 'hourly' && is_numeric($a['hourly_rate'] ?? null)) {
            return ['basis' => 'hourly', 'amount' => $a['hourly_rate'], 'period' => 'hour'];
        }

        if ($basis === 'salary' && is_numeric($a['salary_amount'] ?? null)
            && ($period = self::period($a['salary_period'] ?? '')) !== null) {
            return ['basis' => 'salary', 'amount' => $a['salary_amount'], 'period' => $period];
        }

        return null;
    }

    /** "weekly", "per week", "a year": one period. "Every two weeks" is none. */
    public static function period(string $text): ?string
    {
        $t = strtolower($text);
        if (preg_match('/\b(bi|two|2|semi|every other)\b|bi-?weekly|fortnight/', $t)) {
            return null;
        }

        return match (true) {
            str_contains($t, 'hour') => 'hour',
            str_contains($t, 'week') => 'week',
            str_contains($t, 'month') => 'month',
            str_contains($t, 'year'), str_contains($t, 'annual') => 'year',
            default => null,
        };
    }

    /**
     * "Monday - Friday" with "8:00 AM - 5:00 PM" into the seven days WorkSchedule
     * takes. Null for anything it cannot read for certain.
     *
     * ponytail: two shapes (a day range or a day list, one time range); split
     * shifts or per-day hours stay unread and amber.
     *
     * @return array<string, array{start: string, end: string}|null>|null
     */
    public static function schedule(string $daysText, string $hoursText): ?array
    {
        // The picker's form names the days inside the hours, one group per
        // "; " (UI/UX, 2026-10-01): "Monday to Friday 2:00 PM to 8:00 PM;
        // Sunday 8:00 AM to 8:00 PM". Each group is read on its own.
        if (preg_match('/\b(mon|tue|wed|thu|fri|sat|sun)/i', $hoursText)) {
            $week = array_fill_keys(self::DAYS, null);
            foreach (array_filter(array_map('trim', explode(';', $hoursText))) as $group) {
                if (! preg_match('/^(\D+?)\s*(\d.*)$/', $group, $g)
                    || ($one = self::schedule($g[1], $g[2])) === null) {
                    return null;
                }
                foreach ($one as $day => $window) {
                    if ($window !== null) {
                        $week[$day] = $window;
                    }
                }
            }

            try {
                return WorkSchedule::validate($week);
            } catch (ValidationException) {
                return null;
            }
        }

        preg_match_all('/\b(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?/i', $daysText, $m, PREG_OFFSET_CAPTURE);
        $found = array_map(fn ($hit) => self::day($hit[0]), $m[1]);
        if ($found === []) {
            return null;
        }

        $working = $found;
        if (count($found) === 2) {
            $between = substr($daysText, $m[0][0][1] + strlen($m[0][0][0]), $m[0][1][1] - $m[0][0][1] - strlen($m[0][0][0]));
            if (preg_match('/-|–|—|\bto\b|\bthrough\b|\bthru\b/i', $between)) {
                [$from, $to] = [array_search($found[0], self::DAYS), array_search($found[1], self::DAYS)];
                if ($to < $from) {
                    return null;
                }
                $working = array_slice(self::DAYS, $from, $to - $from + 1);
            }
        }

        preg_match_all('/(\d{1,2})(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?/i', $hoursText, $t, PREG_SET_ORDER);
        if (count($t) !== 2) {
            return null;
        }
        [$start, $end] = [self::clock($t[0]), self::clock($t[1])];
        if ($start === null || $end === null) {
            return null;
        }
        // "8:00 - 5:00" means the afternoon.
        if ($end <= $start && $end < 12 * 60 && empty($t[1][3])) {
            $end += 12 * 60;
        }
        if ($end <= $start || $end > 24 * 60) {
            return null;
        }

        $window = ['start' => self::hhmm($start), 'end' => self::hhmm($end)];
        $days = [];
        foreach (self::DAYS as $day) {
            $days[$day] = in_array($day, $working, true) ? $window : null;
        }

        try {
            return WorkSchedule::validate($days);
        } catch (ValidationException) {
            return null;
        }
    }

    private static function day(string $token): string
    {
        $prefix = strtolower(substr($token, 0, 3));

        return current(array_filter(self::DAYS, fn ($d) => str_starts_with($d, $prefix)));
    }

    /** Minutes after midnight, or null. */
    private static function clock(array $match): ?int
    {
        $h = (int) $match[1];
        $min = (int) ($match[2] ?? 0);
        $half = strtolower($match[3] ?? '');
        if ($h > 23 || $min > 59 || ($half !== '' && ($h < 1 || $h > 12))) {
            return null;
        }
        if ($half === 'p' && $h < 12) {
            $h += 12;
        }
        if ($half === 'a' && $h === 12) {
            $h = 0;
        }

        return $h * 60 + $min;
    }

    private static function hhmm(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }

    private static function date(string $value): ?string
    {
        try {
            return $value === '' ? null : Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
