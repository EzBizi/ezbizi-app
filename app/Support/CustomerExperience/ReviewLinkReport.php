<?php

namespace App\Support\CustomerExperience;

use App\Models\Franchise;
use App\Models\ReviewLinkEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Review links, counted (Jose, 2026-10-15): "how many click the green, how
 * many click the bad, etc ... the amount of messages sent".
 *
 * SENT is a succeeded automation run whose actions mention `review_link` -
 * the text carrying the link, about one job. It exists from the first send
 * (2026-10-08). Everything else is a ReviewLinkEvent, one per link and kind,
 * and exists only from the release that added it: `tracked_since` says when.
 *
 * Everything hangs off the JOB, so branch is the job's franchise and person is
 * who performed it, and both are cut to the franchises in view.
 */
final class ReviewLinkReport
{
    public const COUNTS = ['sent', 'opened', 'happy', 'sad', 'submitted', 'google'];

    /** @return array<string, mixed> */
    public function build(User $user, CarbonInterface $from, CarbonInterface $to, array $franchiseIds): array
    {
        $sent = DB::table('automation_runs as r')
            ->join('automation_events as e', 'e.id', '=', 'r.event_id')
            ->join('jobs as j', 'j.id', '=', 'e.subject_id')
            ->where('r.organization_id', $user->organization_id)
            ->where('e.subject_type', \App\Models\Job::class)
            ->where('r.status', 'succeeded')->where('r.is_test', false)
            ->where('r.actions_snapshot', 'like', '%review_link%')
            ->whereBetween('r.started_at', [$from, $to])
            ->whereIn('j.franchise_id', $franchiseIds)
            ->selectRaw("j.franchise_id, j.performed_by_user_id, 'sent' as kind, count(*) as n");

        $events = DB::table('review_link_events as x')
            ->join('jobs as j', 'j.id', '=', 'x.job_id')
            ->where('x.organization_id', $user->organization_id)
            ->whereBetween('x.occurred_at', [$from, $to])
            ->whereIn('j.franchise_id', $franchiseIds)
            ->selectRaw('j.franchise_id, j.performed_by_user_id, x.kind, count(*) as n');

        $rows = collect($this->grouped($sent))->concat($this->grouped($events));

        $names = Franchise::query()->whereIn('id', $rows->pluck('franchise_id')->unique())->pluck('name', 'id');
        $people = User::query()->whereIn('id', $rows->pluck('performed_by_user_id')->filter()->unique())->pluck('name', 'id');

        return [
            'totals'        => $this->tally($rows),
            'by_branch'     => $this->split($rows, 'franchise_id', fn ($id) => $names[$id] ?? null),
            'by_person'     => $this->split($rows, 'performed_by_user_id', fn ($id) => $id === null ? null : ($people[$id] ?? null)),
            'tracked_since' => ReviewLinkEvent::query()->min('occurred_at'),
        ];
    }

    /** @return list<object> */
    private function grouped(Builder $query): array
    {
        return $query->groupBy('j.franchise_id', 'j.performed_by_user_id', 'kind')->get()->all();
    }

    /** @return array<string, int> */
    private function tally($rows): array
    {
        $out = array_fill_keys(self::COUNTS, 0);
        foreach ($rows as $row) {
            $out[$row->kind] = ($out[$row->kind] ?? 0) + (int) $row->n;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function split($rows, string $key, callable $name): array
    {
        return $rows->groupBy(fn ($r) => (string) $r->{$key})
            ->map(fn ($group, $id) => ['id' => $id === '' ? null : $id, 'name' => $name($id === '' ? null : $id)]
                + $this->tally($group))
            ->sortByDesc('sent')->values()->all();
    }
}
