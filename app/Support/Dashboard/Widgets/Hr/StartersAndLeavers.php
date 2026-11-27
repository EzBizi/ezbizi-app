<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\EmploymentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who has just joined, and who left (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * Starting is dated by each person's first `started` employment event, the
 * start date Records shows — written when an invitation is accepted, moved by
 * HR, and carried over by an import. Not `users.created_at`: an imported
 * account is new and its person is not.
 *
 * Leaving is dated from the day this table shipped. Deactivate writes a `left`
 * row. People who were already inactive before then have no leaving date; they
 * still appear on "No longer active" so the panel does not disagree with Records.
 * History is not invented from `users.updated_at`.
 */
class StartersAndLeavers
{
    use ScopesToPlaces;

    /** How many names the panel lists before it stops and says how many more. */
    private const NAMES_SHOWN = 8;

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);

        // Glances pass the dashboard period start as a Carbon; without one,
        // `days` gives a rolling window via since().
        $since = ($options['since'] ?? null) instanceof Carbon
            ? $options['since']
            : $this->since($options);

        // Their first `started` day, not when the account row was made: the
        // 64 people brought over from A1 Tools on 2026-09-29 have accounts
        // from that day and start dates from years ago.
        $firsts = EmploymentEvent::query()
            ->where('kind', EmploymentEvent::STARTED)
            ->groupBy('user_id')
            ->selectRaw('user_id, min(occurred_on) as first_started');

        $starters = $this->inView($places)
            ->joinSub($firsts, 'firsts', 'firsts.user_id', '=', 'users.id')
            ->where('users.is_active', true)
            ->where('firsts.first_started', '>=', $since->toDateString())
            ->select('users.*', 'firsts.first_started')
            ->orderByDesc('firsts.first_started');

        $started = (clone $starters)->count();

        $inactive = $this->inView($places)->where('is_active', false)->count();

        $left = EmploymentEvent::query()
            ->where('kind', EmploymentEvent::LEFT)
            ->whereDate('occurred_on', '>=', $since->toDateString())
            ->whereIn('user_id', $this->inView($places)->select('id'))
            ->count();

        return [
            'rows' => [
                ['title' => 'Joined', 'total' => $started],
                ['title' => 'Left', 'total' => $left],
                ['title' => 'No longer active', 'total' => $inactive],
            ],

            'total' => $started,
            'days'  => (int) $since->diffInDays(now()),

            'period_applies_to' => ['Joined', 'Left'],

            'note' => 'Leaving dates start from when this record-keeping began. '
                .'People who were already inactive before then have no leaving date.',

            'people' => (clone $starters)->limit(self::NAMES_SHOWN)->get()
                ->map(fn (User $person) => [
                    'id'        => $person->getKey(),
                    'name'      => $person->name,
                    'joined_at' => Carbon::parse($person->first_started)->toIso8601String(),
                ])->all(),

            'more_people' => max(0, $started - self::NAMES_SHOWN),
        ];
    }

    /**
     * People this view covers, assigned or — from the organization — not.
     *
     * Mirrors HrPeopleController::visibleTo, minus its "or it is you" branch:
     * this widget is gated on `hr.view`, which is the same permission that
     * method's `viewAny` asks for, so anybody resolving it may already see
     * everybody in reach. The self branch exists there for people who hold no HR
     * permission at all and can still see their own row.
     */
    private function inView(array $places): Builder
    {
        // removed(false): an erased account is no longer a starter or a
        // leaver (audit 2026-11-27), as HR's own overview already counts.
        return User::query()->removed(false)->where(function (Builder $visible) use ($places) {
            $visible->whereHas(
                'assignments',
                fn (Builder $a) => $this->constrainToPlaces($a, $places),
            );

            if ($this->countsUnplaced($places)) {
                $visible->orWhereDoesntHave('assignments');
            }
        });
    }
}
