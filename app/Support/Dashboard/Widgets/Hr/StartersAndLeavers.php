<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\EmploymentEvent;
use App\Models\User;
use App\Support\Dashboard\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who has just joined, and who left (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * Starting is dated. An account comes into being when an invitation is accepted,
 * so a `started` employment event is written then — the same moment
 * `users.created_at` is set — and this panel reads that event so Records and
 * the dashboard cannot disagree.
 *
 * Leaving is dated from the day this table shipped. Deactivate writes a `left`
 * row. People who were already inactive before then have no leaving date; they
 * still appear on "No longer active" so the panel does not disagree with Records.
 * History is not invented from `users.updated_at`.
 */
class StartersAndLeavers implements Widget
{
    use ScopesToPlaces;

    public const KEY = 'hr.starters_and_leavers';

    public function key(): string { return self::KEY; }

    public function label(): string { return 'Starting and leaving'; }

    public function permission(): string { return 'hr.view'; }

    /** How many names the panel lists before it stops and says how many more. */
    private const NAMES_SHOWN = 8;

    public function options(User $user): array
    {
        return ['days' => $this->periodOption(30)];
    }

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);
        $since  = $this->since($options);

        $starters = $this->inView($places)
            ->where('is_active', true)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at');

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
                    'joined_at' => $person->created_at?->toIso8601String(),
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
        return User::query()->where(function (Builder $visible) use ($places) {
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
