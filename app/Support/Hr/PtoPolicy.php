<?php

namespace App\Support\Hr;

use App\Models\EmploymentEvent;
use App\Models\Organization;
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
     * @return array{
     *     enabled: bool, days_per_year: int, waiting_days: int,
     *     carry_over: bool, started_on: ?string, eligible_from: ?string,
     *     eligible: bool,
     * }
     */
    public function for(User $user): array
    {
        $organization = $user->organization;

        $days = (int) $this->settings->get('pto.days_per_year', $organization);
        $waiting = max(0, (int) $this->settings->get('pto.waiting_days', $organization));

        $startedOn = $this->startedOn($user);

        // A waiting period with no start date to count from is unanswerable,
        // and saying so is the point — see the docblock above.
        $eligibleFrom = $startedOn?->copy()->addDays($waiting);

        return [
            'enabled' => $this->enabledFor($organization),
            'days_per_year' => max(0, $days),
            'waiting_days' => $waiting,
            'carry_over' => (bool) $this->settings->get('pto.carry_over', $organization),
            'started_on' => $startedOn?->toDateString(),
            'eligible_from' => $eligibleFrom?->toDateString(),

            // False when the date is unknown, not true. The safe direction for
            // a sentence somebody acts on: being told to wait when you could
            // have booked costs a conversation, being told you may book when
            // your contract says otherwise costs the company an argument it
            // has already written down.
            'eligible' => $eligibleFrom !== null && ! $eligibleFrom->isFuture(),
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
