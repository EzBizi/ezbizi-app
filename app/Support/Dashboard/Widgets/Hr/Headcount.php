<?php

namespace App\Support\Dashboard\Widgets\Hr;

use App\Models\Assignment;
use App\Models\User;
use App\Support\Dashboard\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many people are in each place (EZBIZI-HR-PLAN.md — Dashboard).
 *
 * ── Why the rows do not add up to the total, on purpose ──────────
 *
 * §4 lets one person hold MANY assignments — that was the requirement cPanel
 * could not meet, "give this employee access to these three brands, not the
 * other ten". Somebody working in two franchises is genuinely part of the
 * headcount of both, so they appear in two rows.
 *
 * The total is therefore counted DISTINCTLY over people rather than by adding
 * the rows up. Summing them would inflate every organization that shares staff
 * between places, and it would do it quietly — the number would merely look a
 * bit high, which is the kind of wrong nobody catches until payroll disagrees.
 * The client says "people" beside the total and names the places beside the
 * rows, so the two are not read as the same count.
 *
 * ── Empty places are shown ───────────────────────────────────────
 *
 * A franchise with nobody in it is the single most useful row on this panel: it
 * is a place that has been set up and never staffed. Hiding it would also make
 * the list reorder itself as people are taken on, which JobsByStatus explains is
 * exactly what you do not want on a board somebody is looking at.
 */
class Headcount implements Widget
{
    use ScopesToPlaces;

    public const KEY = 'hr.headcount';

    public function key(): string { return self::KEY; }

    public function label(): string { return 'Headcount by place'; }

    public function permission(): string { return 'hr.view'; }

    public function options(User $user): array
    {
        return [
            'include_empty' => [
                'label'   => 'Show places with nobody in them',
                'type'    => 'bool',
                'default' => true,
                'help'    => 'On by default. A place that has been set up and never staffed '
                    .'is usually the reason somebody opened this panel.',
            ],
        ];
    }

    public function resolve(User $user, array $options): array
    {
        $places = $this->placesInView($user);
        $names  = $this->placeNames($places, $user);

        // One grouped query rather than one per place. Exactly one of the three
        // `assigned_*` columns is non-null on any row — the database CHECK
        // constraint assignments_exactly_one_scope guarantees it — so grouping
        // by all three yields one row per place and COALESCE picks out which.
        $counts = $this->constrainToPlaces(
            Assignment::query()
                ->join('users', 'users.id', '=', 'assignments.user_id')
                ->where('users.is_active', true),
            $places,
        )
            ->selectRaw(
                'COALESCE(assigned_franchise_id, assigned_company_id, assigned_organization_id) as place_id,'
                .' COUNT(DISTINCT assignments.user_id) as total'
            )
            ->groupBy('place_id')
            ->pluck('total', 'place_id');

        $includeEmpty = (bool) ($options['include_empty'] ?? true);

        $rows = [];

        foreach ($names as $id => $name) {
            $total = (int) ($counts[$id] ?? 0);

            if ($total === 0 && ! $includeEmpty) {
                continue;
            }

            // `title` and `total`, which is the shape the main dashboard's
            // generic row renderer already draws. A widget that invented its own
            // key would need a branch in the client keyed on its name, which is
            // what that renderer's docblock warns against.
            $rows[] = ['title' => $name, 'total' => $total];
        }

        $unplaced = $this->countsUnplaced($places)
            ? User::query()->where('is_active', true)->whereDoesntHave('assignments')->count()
            : 0;

        if ($unplaced > 0) {
            // Named rather than folded into the total silently. Somebody in the
            // organization with no place is a real state the Records tab shows,
            // and a headcount that included them without saying where they were
            // would be a number nobody could reconcile against that list.
            $rows[] = ['title' => 'Not assigned to a place', 'total' => $unplaced];
        }

        return [
            'rows'  => $rows,
            'total' => $this->distinctPeople($places, $unplaced > 0),

            // So the client can say "you have nowhere to count yet" — which
            // asks for a company and a branch — rather than "nobody yet". Read
            // by the HR tab's headcount panel
            // (client/lib/screens/hr/dashboard_tab.dart).
            //
            // Counted off placeNames() and sent, rather than left to the client
            // to count `rows`, because `rows` is the list to DRAW and not a list
            // of places: "Not assigned to a place" is a row above and is not a
            // place, and `include_empty` decides whether a place nobody is in
            // gets a row at all. Neither of those is visible from a client
            // holding the payload, so the number is stated.
            'places' => count($names),
        ];
    }

    /**
     * People, counted once each however many places they work in.
     *
     * @param  bool  $withUnplaced  include people with no assignment — the same
     *                              condition the "Not assigned to a place" row
     *                              is added under, so the two cannot disagree.
     */
    private function distinctPeople(array $places, bool $withUnplaced): int
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $visible) use ($places, $withUnplaced) {
                $visible->whereHas(
                    'assignments',
                    fn (Builder $a) => $this->constrainToPlaces($a, $places),
                );

                if ($withUnplaced) {
                    $visible->orWhereDoesntHave('assignments');
                }
            })
            ->count();
    }
}
