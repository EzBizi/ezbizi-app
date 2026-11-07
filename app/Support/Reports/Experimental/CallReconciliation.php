<?php

namespace App\Support\Reports\Experimental;

use App\Models\CallReportOverride;
use App\Support\Leads\CallTypeCorrections;
use App\Support\Phone\NumberNames;
use App\Support\Reports\Tagging\CallLabels;
use App\Support\Tenancy\TenantContext;

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
 * ── ONE PERSON, TWO SPELLINGS ──────────
 *
 * PersonNames decides, from data: a pair stored on somebody's word, Workiz's
 * own team list, or the one Workiz person with the same first and last word.
 * It was a constant here (ALIASES) until Jose, 2026-11-04: "Shouldnt u be able
 * to update them without an update?" A pair is now a row, not a release.
 */
final class CallReconciliation
{
    /** @var array<string, array<string, array{value: string, by: ?string}>> call id => field => override */
    private array $overrides = [];

    /** @var array<string, PersonNames> organization => its names, read once per instance */
    private array $names = [];

    private function names(): PersonNames
    {
        return $this->names[(string) app(TenantContext::class)->get()?->getKey()] ??= PersonNames::forTenant();
    }

    private function canonical(?string $name): ?string
    {
        return $this->names()->canonical($name);
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
        return $this->forRange($calls, $jobs, $day, $day);
    }

    /**
     * The same report over a PERIOD (Jose, 2026-10-05: "add options to choose
     * period of time"), inclusive of both dates.
     *
     * @param  list<string>  $converted  call ids somebody marked as booked by hand
     * @param  array<string, string>  $tags  person => 'new'|'existing', set by hand
     */
    public function forRange(
        array $calls,
        array $jobs,
        string $from,
        string $to,
        array $converted = [],
        array $tags = [],
    ): array {
        /*
         * SPAM IS NOT A CALL (Jose, 2026-10-05: "remove Spam Calls completely,
         * do not count them"). A caller who sat in the phone menu and pressed
         * nothing is dropped here, before anything is counted, so no total,
         * card or list can include one.
         */
        $calls = array_values(array_filter($calls, fn (array $call) => $this->outcome($call) !== 'spam'));

        /*
         * THE WRONG KEY PRESSED (Jose, 2026-10-16). A new client who pressed
         * "existing", or the reverse, is counted as what they were: Leads
         * decides from a matched customer or job, or the transcript, and
         * keeps the key pressed beside it (CallTypeCorrections).
         */
        $calls = $this->corrected($calls);

        /*
         * OUTBOUND CALLS NOBODY PICKED UP (Jose, 2026-10-12: "on missed calls
         * i want also to differentiate between inbound and outbound calls").
         * Listed beside the missed calls, never counted with them: a customer
         * who did not answer us is not a customer we missed. RingCentral says
         * "Call connected" when the other side or its voicemail picked up, and
         * Hang Up / No Answer / Busy / Call Failed when nobody did.
         */
        $unansweredOut = array_values(array_filter(
            $calls,
            fn (array $call) => ($call['direction'] ?? 'Inbound') === 'Outbound' && ($call['result'] ?? null) !== 'Call connected',
        ));
        // Outbound calls that connected: rows only, never counted, so a search
        // by number finds every call with that customer (Jose, 2026-10-13: "be
        // able to search all calls by number as well").
        $connectedOut = array_values(array_filter(
            $calls,
            fn (array $call) => ($call['direction'] ?? 'Inbound') === 'Outbound' && ($call['result'] ?? null) === 'Call connected',
        ));
        $calls = array_values(array_filter($calls, fn (array $call) => ($call['direction'] ?? 'Inbound') === 'Inbound'));

        $booked = $this->jobsCreatedBetween($jobs, $from, $to);

        /*
         * A CALLER WHO ALREADY HAS A JOB (Jose, 2026-10-13, via Grok AI: a
         * caller moving a job booked a week earlier read "Not booked"). An
         * open job - not Done or Canceled - created before the dates, for the
         * same number, makes the call "Existing job", out of the not-booked
         * count. Only as far back as the jobs read (LOOKBACK_DAYS).
         */
        $openJobs = [];
        foreach ($jobs as $job) {
            $day = substr((string) ($job['created_at'] ?? ''), 0, 10);
            $status = strtolower(trim((string) ($job['status'] ?? '')));
            if ($day !== '' && $day < $from && ! in_array($status, ['done', 'canceled', 'cancelled'], true)) {
                foreach ($this->phones($job) as $phone) {
                    $openJobs[$phone] = ['created_at' => $job['created_at'], 'scheduled_for' => $job['scheduled_for'] ?? null, 'status' => $job['status'] ?? null];
                }
            }
        }
        $existingJob = fn (array $call) => $openJobs[NumberNames::lastTen($call['from'] ?? null) ?? ''] ?? null;

        /*
         * WHICH CALLS BECAME JOBS, matched on the caller's number: a Workiz job
         * created in the period for the same phone (last ten digits). Plus any
         * call somebody marked as booked by hand, for jobs Workiz cannot tie
         * back to a number.
         */
        $bookedPhones = [];

        foreach ($booked as $job) {
            foreach ($this->phones($job) as $phone) {
                $bookedPhones[$phone] = true;
            }
        }

        $converted = array_flip($converted);
        $notBooked = 0;

        $rang = [];
        $answered = [];
        $talkSeconds = [];
        $talkCalls = [];
        $new = [];
        $bookedNew = [];
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

            $client = RingCentralCallLog::clientOf($call['queue'] ?? null);

            if ($client === 'new') {
                $newTotal++;
            } elseif ($client === 'existing') {
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

            if ($client === 'new' && ! $this->isBooked($call, $bookedPhones, $converted) && $existingJob($call) === null) {
                $notBooked++;
            }

            // Attributed to whoever TOOK it. A call that rang four people was
            // one caller with one question, and counting it for all four would
            // make the day's new-client calls outnumber the day's calls.
            if ($client === 'new') {
                $new[$who] = ($new[$who] ?? 0) + 1;
                if ($this->isBooked($call, $bookedPhones, $converted)) {
                    $bookedNew[$who] = ($bookedNew[$who] ?? 0) + 1;
                }
            } elseif ($client === 'existing') {
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
                // Of those new-client calls they answered, the ones booked:
                // the booking rate's top (Trey, 2026-11-04).
                'booked_new' => $bookedNew[$name] ?? 0,
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
                // On Workiz's team list, or booked a job in the window: someone
                // who takes existing clients books nothing and still has an
                // account (Christine Malana, 2026-11-04).
                'in_jobs' => isset($workizRoster[$name]) || $this->names()->inWorkiz($name),

                // Set by hand per person (Jose, 2026-10-05, item 5).
                'tag' => $tags[$name] ?? null,
            ];
        }

        return [
            'day' => $from,
            'from' => $from,
            'to' => $to,
            'totals' => [
                'calls' => count($calls),
                'answered' => array_sum($answered),
                'reached_nobody' => $reachedNobody,
                'new_client' => $newTotal,
                // The Overview's conversion: booked out of answered new-client calls.
                'answered_new' => array_sum($new),
                'booked_new' => array_sum($bookedNew),
                'existing_client' => $existingTotal,
                'jobs' => count($booked),
                'not_booked' => $notBooked,
                'outbound_unanswered' => count($unansweredOut),

                'avg_talk_seconds' => array_sum($talkCalls) > 0
                    ? (int) round(array_sum($talkSeconds) / array_sum($talkCalls))
                    : null,
            ],
            'people' => $people,
            // The business's two queue names, so the screen splits calls by
            // the same setting the counts above used.
            'queues' => ['new' => RingCentralCallLog::newClientQueue(), 'existing' => RingCentralCallLog::existingClientQueue()],
            // And the label lists' names (Settings › Lists), so a label the
            // business added is shown by its own name.
            'labels' => CallLabels::names(),

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
            'calls' => [...array_map(fn (array $call): array => [
                'id' => $call['id'] ?? null,
                'direction' => 'Inbound',
                'to_number' => $call['to_number'] ?? null,
                'started_at' => $call['started_at'] ?? null,
                'from' => $call['from'] ?? null,
                'caller_label' => $call['caller_label'] ?? null,
                'queue' => $call['queue'] ?? null,
                // Set when the caller pressed the other queue's key: what was
                // pressed and why it was re-classified.
                'reclassified' => $call['reclassified'] ?? null,
                'result' => $call['result'] ?? null,
                'outcome' => $this->outcome($call),
                'booked' => $this->isBooked($call, $bookedPhones, $converted),
                'existing_job' => $this->isBooked($call, $bookedPhones, $converted) ? null : $existingJob($call),
                'converted' => isset($converted[(string) ($call['id'] ?? '')]),
                // Who set the client or the booking by hand, per field.
                'set_by' => array_map(fn ($o) => $o['by'], $this->overrides[(string) ($call['id'] ?? '')] ?? []) ?: null,
                'talk_seconds' => $call['talk_seconds'] ?? null,
                'recording_id' => $call['recording_id'] ?? null,
                'answered_by' => $this->canonical($call['answered_by'] ?? null),
                'rang' => array_values(array_filter(array_map(
                    fn ($person) => $this->canonical($person),
                    $call['rang'] ?? [],
                ))),
            ], $calls), ...array_map(fn (array $call): array => [
                'id' => $call['id'] ?? null,
                'direction' => 'Outbound',
                'to_number' => $call['to_number'] ?? null,
                'started_at' => $call['started_at'] ?? null,
                // The number we dialled, where an inbound call has the caller's.
                'from' => $call['to_number'] ?? null,
                'caller_label' => null,
                'queue' => null,
                'result' => $call['result'] ?? null,
                'outcome' => 'no_answer',
                'booked' => false,
                'converted' => false,
                'talk_seconds' => null,
                'recording_id' => null,
                'answered_by' => null,
                'placed_by' => $this->canonical($call['caller_label'] ?? null),
                'rang' => [],
            ], $unansweredOut), ...array_map(fn (array $call): array => [
                'id' => $call['id'] ?? null,
                'direction' => 'Outbound',
                'to_number' => $call['to_number'] ?? null,
                'started_at' => $call['started_at'] ?? null,
                'from' => $call['to_number'] ?? null,
                'caller_label' => null,
                'queue' => null,
                'result' => $call['result'] ?? null,
                'outcome' => 'connected',
                'booked' => false,
                'converted' => false,
                'talk_seconds' => $call['talk_seconds'] ?? null,
                'recording_id' => $call['recording_id'] ?? null,
                'answered_by' => null,
                'placed_by' => $this->canonical($call['caller_label'] ?? null),
                'rang' => [],
            ], $connectedOut)],
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
     * A job's numbers, last ten digits: its Phone and its SecondPhone.
     *
     * @return list<string>
     */
    private function phones(array $job): array
    {
        return array_values(array_filter([
            NumberNames::lastTen($job['phone'] ?? null),
            NumberNames::lastTen($job['second_phone'] ?? null),
        ]));
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
    private function jobsCreatedBetween(array $jobs, string $from, string $to): array
    {
        return array_values(array_filter($jobs, function (array $job) use ($from, $to) {
            $day = substr((string) ($job['created_at'] ?? ''), 0, 10);

            return $day !== '' && $day >= $from && $day <= $to;
        }));
    }

    /**
     * @param  array<string, true>  $bookedPhones
     * @param  array<string, int>  $converted
     */
    private function isBooked(array $call, array $bookedPhones, array $converted): bool
    {
        if (($set = $this->overrides[(string) ($call['id'] ?? '')]['booking'] ?? null) !== null) {
            return $set['value'] === 'booked';
        }

        if (isset($converted[(string) ($call['id'] ?? '')])) {
            return true;
        }

        $phone = NumberNames::lastTen($call['from'] ?? null);

        return $phone !== null && isset($bookedPhones[$phone]);
    }

    /**
     * Each inbound call's queue swapped for the corrected caller type, where
     * Leads has one.
     *
     * @param  list<array<string, mixed>>  $calls
     * @return list<array<string, mixed>>
     */
    private function corrected(array $calls): array
    {
        // Corrections are a tenant's own; a run outside one counts as pressed.
        if (! app(TenantContext::class)->has()) {
            return $calls;
        }

        $ids = array_values(array_filter(array_map(fn ($c) => isset($c['id']) ? (string) $c['id'] : null, $calls)));
        $fixes = CallTypeCorrections::forCalls($ids);
        // Set by hand on the report (Jose, 2026-11-03, for Trey): wins over
        // Leads' read and Workiz, for the client and for the booking.
        $this->overrides = CallReportOverride::forCalls($ids);

        return array_map(function (array $call) use ($fixes) {
            $set = $this->overrides[(string) ($call['id'] ?? '')]['client'] ?? null;
            $fix = $set === null ? ($fixes[(string) ($call['id'] ?? '')] ?? null)
                : ['corrected_type' => $set['value'], 'reason' => 'Set by '.($set['by'] ?? 'someone'), 'evidence' => null, 'decided_by' => $set['by'] ?? 'someone'];
            $queue = match ($fix['corrected_type'] ?? null) {
                'new' => RingCentralCallLog::newClientQueue(),
                'existing' => RingCentralCallLog::existingClientQueue(),
                default => null,
            };

            if ($queue === null || ($call['direction'] ?? 'Inbound') === 'Outbound' || $queue === ($call['queue'] ?? null)) {
                return $call;
            }

            return ['queue' => $queue, 'reclassified' => [
                'pressed' => $call['queue'] ?? null,
                'reason' => $fix['reason'] ?? null,
                'evidence' => $fix['evidence'] ?? null,
                'by_person' => ($fix['decided_by'] ?? null) !== null,
            ]] + $call;
        }, $calls);
    }
}
