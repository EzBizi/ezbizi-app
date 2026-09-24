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
 * Measured 2026-09-14: seven of nine names matched exactly, and RingCentral's
 * "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline Pierrelouis". Nothing here
 * fuzzy-matches them — a near-match rule that merged two similar names would
 * quietly attribute one person's work to another, which is worse than an
 * obvious duplicate row. The union of both name sets is returned and a person
 * who appears on only one side is visible as a row of zeroes on the other.
 *
 * ── AN ALIAS IS SOMEBODY'S ANSWER, NEVER A RESEMBLANCE ──────────
 *
 * `ALIASES` is how two spellings become one person, and every entry in it is a
 * human saying so. Jose, asked whether the two Adelines were the same person:
 * "YES". That is the only thing that may put a row in this list.
 *
 * It is deliberately not a similarity rule, not a normaliser, not an initials
 * match. Those would work on this pair and would also merge two real people
 * who happen to share a surname — silently, and in the direction where one
 * person's work lands on another's row. A list of confirmed pairs cannot do
 * that: it can only be incomplete, which is visible as a duplicate row.
 */
final class CallReconciliation
{
    /**
     * Spellings confirmed to be one person, mapped to the name to keep.
     *
     * Keyed by the RingCentral spelling because that is the one the phone
     * directory holds; the value is the name the report shows. Add only on
     * somebody's word.
     */
    public const ALIASES = [
        // Jose, 2026-09-14, asked whether these are the same person: "YES".
        'Adeline Pierre Louis-Aghaji' => 'Adeline Pierrelouis',
    ];

    private function canonical(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return self::ALIASES[$name] ?? $name;
    }

    /**
     * @param  list<array{from: ?string, queue: ?string, rang: list<string>, answered_by: ?string, talk_seconds?: ?int}>  $calls
     * @param  list<array{account: string, created_by: ?string, created_at: ?string}>  $jobs
     * @return array{
     *     day: string,
     *     totals: array{calls: int, answered: int, reached_nobody: int, new_client: int, existing_client: int, jobs: int, avg_talk_seconds: ?int},
     *     people: list<array{person: string, rang: int, answered: int, new_client: int, existing_client: int, jobs: int, avg_talk_seconds: ?int, in_calls: bool, in_jobs: bool}>
     * }
     */
    public function forDay(array $calls, array $jobs, string $day): array
    {
        $booked = $this->jobsCreatedOn($jobs, $day);

        $rang = [];
        $answered = [];
        $talkSeconds = [];
        $talkCalls = [];
        $new = [];
        $existing = [];

        $reachedNobody = 0;
        $newTotal = 0;
        $existingTotal = 0;

        foreach ($calls as $call) {
            // Nobody ANSWERED, not nobody rang: a call that rang three phones
            // and was picked up by none reached nobody too. Measured 9/23: 25
            // such calls sat in neither card, so Answered + Reached nobody did
            // not add up to Calls.
            if (($call['answered_by'] ?? null) === null) {
                $reachedNobody++;
            }

            foreach ($call['rang'] as $person) {
                $person = $this->canonical($person);
                $rang[$person] = ($rang[$person] ?? 0) + 1;
            }

            $queue = $call['queue'] ?? null;

            if ($queue === RingCentralCallLog::NEW_CLIENT_QUEUE) {
                $newTotal++;
            } elseif ($queue === RingCentralCallLog::EXISTING_CLIENT_QUEUE) {
                $existingTotal++;
            }

            $who = $this->canonical($call['answered_by'] ?? null);

            if ($who === null) {
                continue;
            }

            $answered[$who] = ($answered[$who] ?? 0) + 1;

            // Averaged over ANSWERED calls only. A call nobody picked up has no
            // talk time, and counting it as zero would drag every mean toward
            // nothing while looking like a real measurement.
            $talk = $call['talk_seconds'] ?? null;

            if ($talk !== null) {
                $talkSeconds[$who] = ($talkSeconds[$who] ?? 0) + (int) $talk;
                $talkCalls[$who] = ($talkCalls[$who] ?? 0) + 1;
            }

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
            $person = $this->canonical($job['created_by'] ?? null);

            // Null on 21 of the first 900 live rows. Bucketed by name rather
            // than skipped, so the people column still adds up to the total.
            $person ??= 'No one recorded';
            $made[$person] = ($made[$person] ?? 0) + 1;
        }

        /*
         * ── THE ROSTER IS THE WINDOW, NOT THE DAY ───────────────────
         *
         * `$jobs` is a 30-day window of Workiz jobs; `$booked` is the day's
         * slice of it. "Does Workiz know this name" must be asked of the
         * WINDOW. Asked of the day it answers "did they book anything today",
         * which is a different question with the same shape.
         *
         * That mislabelled three people on the first live day. Jared Prescott,
         * Jovanni Martin and Ranya Lara all answered calls, booked nothing that
         * day, and were marked as though Workiz had never heard of them -
         * which reads as a name spelled differently in the two systems, and
         * invites somebody to go and "fix" a pair that was never broken.
         *
         * Measured 2026-09-15: of the 9 people RingCentral saw in 14 days, 8
         * appear in Workiz spelled IDENTICALLY. The systems agree on names
         * almost perfectly, so a marker firing six times in one day was never
         * reporting what it claimed to.
         */
        $workizRoster = [];

        foreach ($jobs as $job) {
            $name = $this->canonical($job['created_by'] ?? null);

            if ($name !== null) {
                $workizRoster[$name] = true;
            }
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

                // Null rather than 0 when they answered nothing: "no average"
                // and "an average of zero" are different facts, and the screen
                // shows a dash for the first.
                'avg_talk_seconds' => isset($talkCalls[$name]) && $talkCalls[$name] > 0
                    ? (int) round($talkSeconds[$name] / $talkCalls[$name])
                    : null,

                // Whether WORKIZ HAS EVER HEARD OF THEM, over the whole
                // window - not whether they booked today.
                //
                // Only this direction is asserted. The reverse ("Workiz knows
                // them, RingCentral does not") would need a roster of callers
                // and all this holds is one day of calls, so somebody who books
                // jobs without answering phones - a normal way to work - must
                // not be flagged as a mismatch.
                'in_calls' => isset($rang[$name]) || isset($answered[$name]),
                'in_jobs' => isset($workizRoster[$name]),
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

                'avg_talk_seconds' => array_sum($talkCalls) > 0
                    ? (int) round(array_sum($talkSeconds) / array_sum($talkCalls))
                    : null,
            ],
            'people' => $people,

            /*
             * ── THE CALLS THEMSELVES, FLAT ──────────────────────────
             *
             * So a person's row can be opened and the calls behind its numbers
             * read. Sent ONCE as a flat list rather than nested under each
             * person, because a queue call rings several people and nesting
             * would send the same call three or four times - the same
             * double-count the `rang` column exists to warn about, arriving
             * this time as bytes.
             *
             * The client picks a person's calls by matching `answered_by` or
             * membership of `rang`, which is exactly what the two columns mean.
             *
             * Names are canonicalised here, the same way the columns are. Left
             * raw, a drill-down would show "Adeline Pierre Louis-Aghaji" under
             * a row labelled with whatever canonical() made of it, and the two
             * would look like different people.
             */
            'calls' => array_map(fn (array $call): array => [
                'id' => $call['id'] ?? null,
                'started_at' => $call['started_at'] ?? null,
                'from' => $call['from'] ?? null,
                'caller_label' => $call['caller_label'] ?? null,
                'queue' => $call['queue'] ?? null,
                'result' => $call['result'] ?? null,
                'outcome' => $this->outcome($call),
                'talk_seconds' => $call['talk_seconds'] ?? null,
                'recording_id' => $call['recording_id'] ?? null,
                'answered_by' => $this->canonical($call['answered_by'] ?? null),
                'rang' => array_values(array_filter(array_map(
                    fn ($person) => $this->canonical($person),
                    $call['rang'] ?? [],
                ))),
            ], $calls),
        ];
    }

    /**
     * Why a call reached nobody, in Jose's words (2026-09-24).
     *
     * Read off RingCentral's record `result`, measured on 9/23's 664 inbound
     * calls: `Accepted` with no answer is a caller who sat in the IVR and
     * pressed nothing (136), `Missed` rang a queue or phone and nobody took it
     * (95), `Voicemail` left a message (12), `Blocked`/`Rejected` never got
     * in (14). Anything else is `other` rather than folded into a bucket it
     * was never measured in.
     *
     * @param  array<string, mixed>  $call
     */
    private function outcome(array $call): string
    {
        if (($call['answered_by'] ?? null) !== null) {
            return 'answered';
        }

        return match ($call['result'] ?? null) {
            'Accepted' => 'spam',
            'Missed' => 'missed',
            'Voicemail' => 'voicemail',
            'Blocked', 'Rejected' => 'blocked',
            default => 'other',
        };
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
