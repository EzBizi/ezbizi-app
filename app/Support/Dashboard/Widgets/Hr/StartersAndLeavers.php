<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\User;
use App\Support\Dashboard\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who has just joined, and who is no longer active
 * (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── The halves are not symmetric, and the panel says so ──────────
 *
 * Starting is dated. An account comes into being when an invitation is accepted,
 * so `users.created_at` is a real timestamp for "when this person joined" — it is
 * already what HrPeopleController presents as `joined_at`, and this reads the
 * same column so the two cannot disagree.
 *
 * Leaving is NOT dated. §5's rule is that deactivate is not delete, and the
 * record survives as `users.is_active = false` — a boolean with no timestamp
 * beside it. Nothing in the schema records WHEN somebody was deactivated:
 *
 *   - `audit_events` holds the change, but as a generic `user.updated` row with
 *     `is_active` inside a JSON `changes` blob. Reading a dashboard figure out of
 *     that means matching an action name and parsing JSON, and it would go blank
 *     the day §10's retention sweep expires the row — a leaver disappearing from
 *     history because a log rotated is worse than not offering the number.
 *   - `automation_events` holds `user.deactivated`, but that table is the
 *     automation engine's queue. Reading HR facts out of another module's work
 *     log is the second home §6 warns about, and it would tie this panel's
 *     correctness to a retention policy that belongs to something else.
 *
 * So the leaving half is reported WITHOUT a period: how many people are no
 * longer active, full stop. That is true, it is answerable from a first-class
 * column, and it does not invent an employment-lifecycle shape that onboarding
 * would then have to match. `period_applies_to` tells the client which half the
 * window covers so it can label them honestly rather than implying both are
 * "in the last 30 days" — see EZBIZI-TODO.md for the decision this is waiting on.
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

        return [
            'rows' => [
                ['title' => 'Joined', 'total' => $started],
                ['title' => 'No longer active', 'total' => $inactive],
            ],

            'total' => $started,
            'days'  => (int) $since->diffInDays(now()),

            /*
             * Which row the window actually covers.
             *
             * The client draws the period beside the first row and a plain count
             * beside the second. Sent as data rather than left to the client to
             * know, because a client that hardcoded "the first row is the dated
             * one" would silently start lying the day a third row is added.
             */
            'period_applies_to' => ['Joined'],

            // The names, for the panel that is about people rather than about a
            // number. Capped: this is a summary, and the Records tab is the list.
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
