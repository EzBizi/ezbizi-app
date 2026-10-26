<?php

namespace App\Support\Hr;

use App\Models\EmploymentEvent;
use App\Models\Organization;
use App\Models\PtoUsed;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Carbon;

/**
 * What paid time off somebody is allowed, and when they may start using it.
 *
 * From a production suggestion (Toula Elwell, A1-Group, 2026-09-08): "how much
 * paid pto we all have and when it can be used". Approved by Jose 2026-09-17.
 *
 * ── The second half of her sentence is the whole reason this exists ──
 *
 * A balance is a number and somebody could keep it in a spreadsheet. "When it
 * can be used" is a rule — a waiting period counted from the day a person
 * started — and it is the part nobody in a company can look up, because it
 * lives in a contract PDF if it exists at all. This class answers it from the
 * organization's policy and the person's own start date.
 *
 * ── What this does NOT do, deliberately ─────────────────────────
 *
 * It does not know what anybody has TAKEN. There is no record of a day off
 * anywhere in EzBizi today — I measured before writing this: the scheduling
 * tables are service areas and coverage slots, Clock In records hours worked,
 * and nothing anywhere records absence. Where that record should live is an
 * open question with the Scheduling session, because a calendar that greys out
 * a technician who is away and an HR balance that subtracts the same day are
 * the same fact, and two stores of it would drift. So this reports the
 * ALLOWANCE and the eligibility date, which are true regardless of how that
 * question is answered, and the balance follows when it is.
 *
 * ── And EzBizi decides none of the numbers ───────────────────────
 *
 * Days, waiting periods and carryover are terms of employment. They vary by
 * company and by state, and the only place they exist in this product today is
 * the contract templates — where exactly one of eleven mentions PTO. So every
 * value here comes from the organization's own settings, and the defaults are
 * off and zero rather than anything that looks reasonable: a company shown a
 * six-day allowance it never agreed to would be reading its own policy off our
 * guess.
 */
class PtoPolicy
{
    public function __construct(private readonly SettingsResolver $settings) {}

    public function enabledFor(Organization $organization): bool
    {
        return (bool) $this->settings->get('pto.enabled', $organization);
    }

    /**
     * The policy as it applies to one person, with the date it opens.
     *
     * `started_on` is null for anybody who has no `started` employment event —
     * which is every person on the books before that table existed, by
     * deliberate design: the migration refused to invent joining dates from
     * `updated_at`. So `eligible_from` is null too, and the screen must say it
     * does not know rather than compute from a date it made up. An invented
     * start date would make somebody eligible three months early or late, and
     * nothing would ever say why.
     *
     * Both numbers carry a unit and NEITHER is converted. Six days a year and
     * half a day a month are the same allowance said two ways, but only one of
     * them is what the contract says, and annualising on the way through would
     * hand somebody a figure their own policy does not contain. The waiting
     * period is the sharper case: from 31 January 2026, three months is 30
     * April and ninety days is 1 May, and a person told the wrong one of those
     * has been told a wrong date about their own leave. (Both measured. The
     * pair I reached for first — 30 November, three months against ninety days
     * — lands on 28 February either way, which is the example that would have
     * proved nothing.)
     *
     * @return array{
     *     enabled: bool, allowance: int, allowance_period: string,
     *     waiting: int, waiting_unit: string,
     *     accrue_after: int, accrue_after_unit: string, accrues_from: ?string,
     *     carry_over: bool, started_on: ?string, eligible_from: ?string,
     *     eligible: bool,
     * }
     */
    public function for(User $user): array
    {
        $organization = $user->organization;

        $allowance = (int) $this->settings->get('pto.allowance', $organization);
        $waiting = max(0, (int) $this->settings->get('pto.waiting', $organization));
        $unit = (string) $this->settings->get('pto.waiting_unit', $organization);
        $accrueAfter = max(0, (int) $this->settings->get('pto.accrue_after', $organization));
        $accrueUnit = (string) $this->settings->get('pto.accrue_after_unit', $organization);

        $startedOn = $this->startedOn($user);

        // A waiting period with no start date to count from is unanswerable,
        // and saying so is the point — see the docblock above.
        //
        // The NoOverflow variants, and measured rather than assumed: the
        // generic `add('month', 3)` from 31 January lands on 1 MAY, because
        // April has no 31st and Carbon rolls forward into the next month. A
        // contract saying "three months" from 31 January means 30 April to
        // every person who signs one, and a product that answers 1 May has
        // moved somebody's leave by a day for a reason no one can see.
        $eligibleFrom = $startedOn === null ? null : match ($unit) {
            'week' => $startedOn->copy()->addWeeks($waiting),
            'month' => $startedOn->copy()->addMonthsNoOverflow($waiting),
            'quarter' => $startedOn->copy()->addMonthsNoOverflow(3 * $waiting),
            'year' => $startedOn->copy()->addYearsNoOverflow($waiting),
            default => $startedOn->copy()->addDays($waiting),
        };

        $period = (string) $this->settings->get('pto.allowance_period', $organization);
        $carryOver = (bool) $this->settings->get('pto.carry_over', $organization);
        $reset = (string) $this->settings->get('pto.reset', $organization);
        $accruesFrom = $startedOn === null ? null : $this->after($startedOn, $accrueAfter, $accrueUnit);
        $balance = $this->balance($user, $startedOn, $accruesFrom, max(0, $allowance), $period, $reset, $carryOver);

        return [
            'enabled' => $this->enabledFor($organization),
            'allowance' => max(0, $allowance),
            'allowance_period' => $period,
            'reset' => $reset,

            // Earned per period, used up by the reset (Jose, 2026-10-21: "if
            // you started 1 Apr 2025 you have until 1 Apr 2026"), less the days
            // recorded as used. Null without a start date: what somebody
            // earned cannot be counted from a day nobody knows.
            ...$balance,
            'waiting' => $waiting,
            'waiting_unit' => $unit,
            'accrue_after' => $accrueAfter,
            'accrue_after_unit' => $accrueUnit,
            'accrues_from' => $accruesFrom?->toDateString(),
            'carry_over' => (bool) $this->settings->get('pto.carry_over', $organization),
            'started_on' => $startedOn?->toDateString(),
            'eligible_from' => $eligibleFrom?->toDateString(),

            // False when the date is unknown, not true. The safe direction for
            // a sentence somebody acts on: being told to wait when you could
            // have booked costs a conversation, being told you may book when
            // your contract says otherwise costs the company an argument it
            // has already written down.
            //
            // Except with no waiting period at all, where the unknown start
            // date stops mattering: a policy usable from the first day is
            // usable by anybody who is here, and the date it would be counted
            // from cannot change that. Without this clause every person with no
            // `started` event — which is everybody who predates that table — is
            // told to wait for a date that is already behind them.
            'eligible' => $waiting === 0
                || ($eligibleFrom !== null && ! $eligibleFrom->isFuture()),
        ];
    }

    /**
     * Total, used and left in the current reset window, and when it resets.
     *
     * The window runs from the last reset (each work anniversary, each
     * 1 January, or each earned period) to the next. What was earned is whole
     * periods counted from the day accumulation starts — the start date, unless
     * the organization set a one-time wait. With carryover, what was left at
     * the last reset comes along as `carried`. Undated used days ("before
     * EzBizi") count in the current window.
     *
     * @return array{total: ?float, used: float, left: ?float, carried: ?float, expired: ?float, resets_on: ?string, window_from: ?string}
     */
    private function balance(User $user, ?Carbon $startedOn, ?Carbon $accruesFrom, int $allowance, string $period, string $reset, bool $carryOver): array
    {
        $usedQuery = fn () => PtoUsed::query()->where('user_id', $user->getKey());
        if ($startedOn === null || $startedOn->isFuture()) {
            return ['total' => null, 'used' => (float) $usedQuery()->sum('days'), 'left' => null,
                'carried' => null, 'expired' => null, 'resets' => [], 'earned' => [], 'upcoming_earned' => [], 'would_expire' => null, 'resets_on' => null, 'window_from' => null];
        }

        // Zero wait: accumulation starts on the start date, which is what every
        // balance did before the wait existed.
        $origin = $accruesFrom ?? $startedOn;
        $step = fn (Carbon $at, int $n) => match ($period) {
            'week' => $at->copy()->addWeeks($n),
            'month' => $at->copy()->addMonthsNoOverflow($n),
            'quarter' => $at->copy()->addMonthsNoOverflow(3 * $n),
            default => $at->copy()->addYearsNoOverflow($n),
        };
        // Whole periods from the day accumulation starts to $until. A date
        // before that day has earned nothing. ponytail: counts one by one;
        // decades of weeks is a few thousand steps.
        $periods = function (Carbon $until) use ($origin, $step): int {
            if ($until->lt($origin)) {
                return 0;
            }
            $n = 0;
            while ($step($origin, $n + 1)->lte($until)) {
                $n++;
            }

            return $n;
        };
        $today = now()->startOfDay();

        [$from, $next] = match ($reset) {
            'anniversary' => (function () use ($startedOn, $today) {
                $k = (int) $startedOn->diffInYears($today);
                while ($startedOn->copy()->addYearsNoOverflow($k + 1)->lte($today)) {
                    $k++;
                }

                return [$startedOn->copy()->addYearsNoOverflow($k), $startedOn->copy()->addYearsNoOverflow($k + 1)];
            })(),
            // The period grid moves with the wait. Before it starts, the
            // window is the one that is about to open and it holds nothing.
            'period' => $today->lt($origin)
                ? [$origin->copy(), $step($origin, 1)]
                : [$step($origin, $periods($today)), $step($origin, $periods($today) + 1)],
            default => [$startedOn->copy()->max($today->copy()->startOfYear()), $today->copy()->startOfYear()->addYear()],
        };

        // Reset every period: that period's days are there from its first day,
        // and not before accumulation has started.
        $earnedNow = $reset === 'period'
            ? ($today->lt($origin) ? 0 : $allowance)
            : ($periods($today) - $periods($from)) * $allowance;
        // Every past reset, played forward from the start date: what was left
        // at each either carried into the next window or expired (Jose,
        // 2026-10-22: "show when past resets happened").
        $bounds = [$startedOn->copy()];
        $at = $startedOn->copy();
        for ($i = 0; $i < 600; $i++) {
            $at = match ($reset) {
                'anniversary' => $startedOn->copy()->addYearsNoOverflow($i + 1),
                'period' => $step($startedOn, $i + 1),
                default => $at->copy()->startOfYear()->addYear(),
            };
            if ($at->gt($from)) {
                break;
            }
            $bounds[] = $at->copy();
        }
        $usedRows = $usedQuery()->whereNotNull('used_on')->where('used_on', '<', $from->toDateString())
            ->get(['used_on', 'days']);
        $carriedIn = 0.0;
        $resets = [];
        for ($i = 1; $i < count($bounds); $i++) {
            [$a, $b] = [$bounds[$i - 1], $bounds[$i]];
            $earned = $reset === 'period' ? $allowance : ($periods($b) - $periods($a)) * $allowance;
            $spent = (float) $usedRows->filter(fn ($r) => $r->used_on->gte($a) && $r->used_on->lt($b))->sum('days');
            $leftover = max(0.0, $earned + $carriedIn - $spent);
            $carriedIn = $carryOver ? $leftover : 0.0;
            $resets[] = ['on' => $b->toDateString(), 'expired' => round($carryOver ? 0.0 : $leftover, 2),
                'carried' => round($carriedIn, 2)];
        }
        $carried = $carriedIn;
        $expired = (float) array_sum(array_column($resets, 'expired'));
        $usedNow = (float) $usedQuery()->where(fn ($q) => $q->whereNull('used_on')
            ->orWhere('used_on', '>=', $from->toDateString()))->sum('days');
        $total = $earnedNow + $carried;

        return [
            'total' => round($total, 2),
            'used' => round($usedNow, 2),
            'left' => round($total - $usedNow, 2),
            'carried' => round($carried, 2),
            'expired' => round($expired, 2),
            // Newest first, the last 24.
            'resets' => array_slice(array_reverse($resets), 0, 24),
            // Every grant that has already landed, newest first. The timeline
            // says when the days arrived; a future period is not earned yet.
            'earned' => $this->grants($origin, $today, $allowance, $step, $reset),
            // The grants still to land in this period, up to the reset, oldest
            // first (UI/UX, 2026-10-26: "when the next days are earned").
            'upcoming_earned' => $this->upcoming($origin, $today, $next, $allowance, $step, $reset),
            // Lost at the next reset if nothing more is used (carryover off).
            'would_expire' => $carryOver ? 0.0 : round(max(0.0, $total - $usedNow), 2),
            'resets_on' => $next->toDateString(),
            'window_from' => $from->toDateString(),
        ];
    }

    /**
     * The days each finished earn period added, newest first.
     *
     * A period that resets on its own boundary puts the allowance on the
     * first day of that period. Every other reset waits until the period has
     * finished. Nothing after today is included: those days are not earned.
     *
     * @param  callable(Carbon, int): Carbon  $step
     * @return list<array{on: string, days: int}>
     */
    private function grants(Carbon $origin, Carbon $today, int $allowance, callable $step, string $reset): array
    {
        if ($allowance <= 0 || $today->lt($origin)) {
            return [];
        }

        $rows = [];
        $first = $reset === 'period' ? 0 : 1;
        for ($n = $first; $n < 600; $n++) {
            $at = $step($origin, $n);
            if ($at->gt($today)) {
                break;
            }
            $rows[] = ['on' => $at->toDateString(), 'days' => $allowance];
        }

        return array_reverse($rows);
    }

    /**
     * The grants after today, up to and including the reset day, oldest first:
     * the same steps grants() counts, looked at forward.
     *
     * @param  callable(Carbon, int): Carbon  $step
     * @return list<array{on: string, days: int}>
     */
    private function upcoming(Carbon $origin, Carbon $today, Carbon $until, int $allowance, callable $step, string $reset): array
    {
        if ($allowance <= 0) {
            return [];
        }
        $rows = [];
        for ($n = $reset === 'period' ? 0 : 1; $n < 600; $n++) {
            $at = $step($origin, $n);
            if ($at->gt($until)) {
                break;
            }
            if ($at->gt($today)) {
                $rows[] = ['on' => $at->toDateString(), 'days' => $allowance];
            }
        }

        return $rows;
    }

    /**
     * A count of days, weeks or months after a date, without overflowing.
     *
     * Three months from 31 January is 30 April. Ninety days is 1 May. The
     * unit the policy named is the one that has to come back.
     */
    private function after(Carbon $from, int $count, string $unit): Carbon
    {
        return match ($unit) {
            'week' => $from->copy()->addWeeks($count),
            'month' => $from->copy()->addMonthsNoOverflow($count),
            default => $from->copy()->addDays($count),
        };
    }

    /**
     * The day they started, from the employment record rather than the account.
     *
     * `users.created_at` is when a row was written, which is import time for
     * anybody brought over from another system — see the same trap recorded for
     * imported customers. The earliest `started` event is the fact somebody
     * would recognise as their own joining date.
     */
    private function startedOn(User $user): ?Carbon
    {
        $on = EmploymentEvent::query()
            ->where('user_id', $user->getKey())
            ->where('kind', EmploymentEvent::STARTED)
            ->orderBy('occurred_on')
            ->value('occurred_on');

        return $on === null ? null : Carbon::parse($on)->startOfDay();
    }
}
