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
        $balance = $this->balance($user, $startedOn, max(0, $allowance), $period, $reset, $carryOver);

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
     * periods counted from the start date; with carryover, what was left at
     * the last reset comes along as `carried`. Undated used days ("before
     * EzBizi") count in the current window.
     *
     * @return array{total: ?float, used: float, left: ?float, carried: ?float, resets_on: ?string, window_from: ?string}
     */
    private function balance(User $user, ?Carbon $startedOn, int $allowance, string $period, string $reset, bool $carryOver): array
    {
        $usedQuery = fn () => PtoUsed::query()->where('user_id', $user->getKey());
        if ($startedOn === null || $startedOn->isFuture()) {
            return ['total' => null, 'used' => (float) $usedQuery()->sum('days'), 'left' => null,
                'carried' => null, 'resets_on' => null, 'window_from' => null];
        }

        $step = fn (Carbon $at, int $n) => match ($period) {
            'week' => $at->copy()->addWeeks($n),
            'month' => $at->copy()->addMonthsNoOverflow($n),
            'quarter' => $at->copy()->addMonthsNoOverflow(3 * $n),
            default => $at->copy()->addYearsNoOverflow($n),
        };
        // Whole periods from the start date to $until. ponytail: counts one by
        // one; decades of weeks is a few thousand steps.
        $periods = function (Carbon $until) use ($startedOn, $step): int {
            $n = 0;
            while ($step($startedOn, $n + 1)->lte($until)) {
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
            'period' => [$step($startedOn, $periods($today)), $step($startedOn, $periods($today) + 1)],
            default => [$startedOn->copy()->max($today->copy()->startOfYear()), $today->copy()->startOfYear()->addYear()],
        };

        // Reset every period: that period's days are there from its first day.
        $earnedNow = $reset === 'period' ? $allowance : ($periods($today) - $periods($from)) * $allowance;
        $earnedBefore = $periods($from) * $allowance;
        $usedNow = (float) $usedQuery()->where(fn ($q) => $q->whereNull('used_on')
            ->orWhere('used_on', '>=', $from->toDateString()))->sum('days');
        $usedBefore = (float) $usedQuery()->where('used_on', '<', $from->toDateString())->sum('days');
        $carried = $carryOver ? max(0.0, $earnedBefore - $usedBefore) : 0.0;
        $total = $earnedNow + $carried;

        return [
            'total' => round($total, 2),
            'used' => round($usedNow, 2),
            'left' => round($total - $usedNow, 2),
            'carried' => round($carried, 2),
            'resets_on' => $next->toDateString(),
            'window_from' => $from->toDateString(),
        ];
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
