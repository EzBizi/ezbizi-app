<?php

namespace App\Support\Reports\Experimental;

/**
 * One day, per person: calls taken, what the caller wanted, jobs booked (TEMPORARY).
 *
 * ── Every number here was rewritten against live data ────────────
 *
 * The first version of this class was built on invented fixtures and agreed
 * with itself perfectly. Measured against the real APIs on 2026-09-14 it was
 * wrong in three ways at once, and each one produced a plausible number:
 *
 * 1. It attributed calls from the top-level record, where the person is null on
 *    278 of 280 inbound calls. Everyone would have scored zero.
 * 2. It read Workiz's `JobDateTime` as the booking time. That is the
 *    APPOINTMENT, up to a year later, so "jobs booked today" was really "vans
 *    turning up today" — a different set of jobs, similar in size.
 * 3. It derived new-vs-existing by checking whether the caller's number
 *    appeared on an older Workiz job. A-1 already routes that distinction
 *    through named queues, so the guess was both unnecessary and worse.
 *
 * ── RANG and ANSWERED are different questions ────────────────────
 *
 * A queue call rings three or four people in turn until somebody picks up. So
 * "how many calls did they receive" has two honest answers and this returns
 * both: `rang` counts every call that made their phone ring, `answered` counts
 * the ones they took. Summing `answered` across people gives the day's answered
 * calls; summing `rang` does not give anything, and the screen must not add it
 * up.
 *
 * ── The two systems do not agree on people's names ───────────────
 *
 * Measured the same day: seven of nine names matched exactly, and RingCentral's
 * "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline Pierrelouis". Nothing here
 * fuzzy-matches them — a near-match rule that merged two similar names would
 * quietly attribute one person's work to another, which is worse than an
 * obvious duplicate row. The union of both name sets is returned and a person
 * who appears on only one side is visible as a row of zeroes on the other.
 */
final class CallReconciliation
{
    /**
     * @param  list<array{from: ?string, queue: ?string, rang: list<string>, answered_by: ?string}>  $calls
     * @param  list<array{account: string, created_by: ?string, created_at: ?string}>  $jobs
     * @return array{
     *     day: string,
     *     totals: array{calls: int, answered: int, reached_nobody: int, new_client: int, existing_client: int, jobs: int},
     *     people: list<array{person: string, rang: int, answered: int, new_client: int, existing_client: int, jobs: int, in_calls: bool, in_jobs: bool}>
     * }
     */
    public function forDay(array $calls, array $jobs, string $day): array
    {
        $booked = $this->jobsCreatedOn($jobs, $day);

        $rang = [];
        $answered = [];
        $new = [];
        $existing = [];

        $reachedNobody = 0;
        $newTotal = 0;
        $existingTotal = 0;

        foreach ($calls as $call) {
            if ($call['rang'] === []) {
                $reachedNobody++;
            }

            foreach ($call['rang'] as $person) {
                $rang[$person] = ($rang[$person] ?? 0) + 1;
            }

            $queue = $call['queue'] ?? null;

            if ($queue === RingCentralCallLog::NEW_CLIENT_QUEUE) {
                $newTotal++;
            } elseif ($queue === RingCentralCallLog::EXISTING_CLIENT_QUEUE) {
                $existingTotal++;
            }

            $who = $call['answered_by'] ?? null;

            if ($who === null) {
                continue;
            }

            $answered[$who] = ($answered[$who] ?? 0) + 1;

            // Attributed to whoever TOOK it. A call that rang four people was
            // one caller with one question, and counting it for all four would
            // make the day's new-client calls outnumber the day's calls.
            if ($queue === RingCentralCallLog::NEW_CLIENT_QUEUE) {
                $new[$who] = ($new[$who] ?? 0) + 1;
            } elseif ($queue === RingCentralCallLog::EXISTING_CLIENT_QUEUE) {
                $existing[$who] = ($existing[$who] ?? 0) + 1;
            }
        }

        $made = [];

        foreach ($booked as $job) {
            $person = $job['created_by'] ?? null;

            // Null on 21 of the first 900 live rows. Bucketed by name rather
            // than skipped, so the people column still adds up to the total.
            $person ??= 'No one recorded';
            $made[$person] = ($made[$person] ?? 0) + 1;
        }

        $names = array_values(array_unique(array_merge(
            array_keys($rang),
            array_keys($answered),
            array_keys($made),
        )));

        sort($names);

        $people = [];

        foreach ($names as $name) {
            $people[] = [
                'person' => $name,
                'rang' => $rang[$name] ?? 0,
                'answered' => $answered[$name] ?? 0,
                'new_client' => $new[$name] ?? 0,
                'existing_client' => $existing[$name] ?? 0,
                'jobs' => $made[$name] ?? 0,

                // Which system knows this name. A person present on one side
                // only is the signal that the two systems spell them
                // differently, and the screen says so instead of guessing.
                'in_calls' => isset($rang[$name]) || isset($answered[$name]),
                'in_jobs' => isset($made[$name]),
            ];
        }

        return [
            'day' => $day,
            'totals' => [
                'calls' => count($calls),
                'answered' => array_sum($answered),
                'reached_nobody' => $reachedNobody,
                'new_client' => $newTotal,
                'existing_client' => $existingTotal,
                'jobs' => count($booked),
            ],
            'people' => $people,
        ];
    }

    /**
     * Jobs BOOKED on the day, from `created_at`.
     *
     * Filtered here because Workiz cannot be asked for it: its `start_date`
     * parameter filters the appointment date, not the booking date. Measured
     * 2026-09-14 — a job created 2025-10-29 came back inside a window starting
     * 2026-09-01 because its appointment was 2026-10-22.
     *
     * @param  list<array{created_at: ?string}>  $jobs
     * @return list<array{created_at: ?string}>
     */
    private function jobsCreatedOn(array $jobs, string $day): array
    {
        return array_values(array_filter(
            $jobs,
            fn (array $job) => str_starts_with((string) ($job['created_at'] ?? ''), $day),
        ));
    }
}
