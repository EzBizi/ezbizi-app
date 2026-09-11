<?php

namespace App\Support\Reports\Experimental;

/**
 * Calls per person for one day, split new against existing (TEMPORARY).
 *
 * ── The two counting rules, stated because they are choices ──────
 *
 * RECEIVED means an INBOUND call that reached a person, answered or missed.
 * Attribution is per LEG, so a queue call that rang four people and was
 * answered by one counts as answered for that one and is not counted four
 * times. Outbound calls are excluded entirely: "how many calls did they
 * receive" is not a question about calls they made.
 *
 * NEW means the caller's number appears on no prior Workiz job. That is a
 * PROXY for "new client" rather than the thing itself — somebody in the client
 * list who has never had a job books as new — and the screen says so rather
 * than letting the number pass as more than it is.
 *
 * ── Numbers are compared on their DIGITS ────────────────────────
 *
 * RingCentral says `+15551234567`, Workiz says `(555) 123-4567`, and the same
 * person is a different string in each. Matching on formatted text would put
 * every caller in the NEW column and the report would look plausible while
 * being entirely wrong. The last ten digits are compared, which is correct for
 * NANP numbers and is all this temporary report is asked to handle.
 */
final class CallReconciliation
{
    /**
     * @param  list<array{extension: ?string, person: ?string, direction: ?string, result: ?string, from: ?string, to: ?string, started_at: ?string}>  $calls
     * @param  list<array{phone: ?string, created_by: ?string, created_at: ?string, status: ?string}>  $jobs
     * @return array{
     *     people: list<array{person: string, received: int, from_new: int, from_existing: int, missed: int, workiz_jobs: int, difference: int}>,
     *     totals: array{received: int, from_new: int, from_existing: int, missed: int, workiz_jobs: int}
     * }
     */
    public function forDay(array $calls, array $jobs, string $day): array
    {
        $known = $this->knownNumbers($jobs);
        $jobsByPerson = $this->jobsCreatedOn($jobs, $day);

        $people = [];

        foreach ($calls as $call) {
            if (($call['direction'] ?? null) !== 'Inbound') {
                continue;
            }

            $person = $call['person'] ?: ($call['extension'] ?: 'Unassigned');

            $people[$person] ??= [
                'person' => $person, 'received' => 0, 'from_new' => 0,
                'from_existing' => 0, 'missed' => 0, 'workiz_jobs' => 0, 'difference' => 0,
            ];

            $people[$person]['received']++;

            if (($call['result'] ?? null) === 'Missed') {
                $people[$person]['missed']++;
            }

            $digits = $this->digits($call['from'] ?? null);

            if ($digits !== null && isset($known[$digits])) {
                $people[$person]['from_existing']++;
            } else {
                $people[$person]['from_new']++;
            }
        }

        // Somebody who created jobs but took no calls still belongs on the
        // report: a row missing entirely reads as "no data" when it is in fact
        // the most interesting row on the page.
        foreach ($jobsByPerson as $person => $count) {
            $people[$person] ??= [
                'person' => $person, 'received' => 0, 'from_new' => 0,
                'from_existing' => 0, 'missed' => 0, 'workiz_jobs' => 0, 'difference' => 0,
            ];
        }

        foreach ($people as $person => $row) {
            $people[$person]['workiz_jobs'] = $jobsByPerson[$person] ?? 0;
            $people[$person]['difference'] = $row['received'] - ($jobsByPerson[$person] ?? 0);
        }

        ksort($people);
        $people = array_values($people);

        return [
            'people' => $people,
            'totals' => [
                'received'      => array_sum(array_column($people, 'received')),
                'from_new'      => array_sum(array_column($people, 'from_new')),
                'from_existing' => array_sum(array_column($people, 'from_existing')),
                'missed'        => array_sum(array_column($people, 'missed')),
                'workiz_jobs'   => array_sum(array_column($people, 'workiz_jobs')),
            ],
        ];
    }

    /** @return array<string, true> */
    private function knownNumbers(array $jobs): array
    {
        $out = [];

        foreach ($jobs as $job) {
            $digits = $this->digits($job['phone'] ?? null);

            if ($digits !== null) {
                $out[$digits] = true;
            }
        }

        return $out;
    }

    /** @return array<string, int> */
    private function jobsCreatedOn(array $jobs, string $day): array
    {
        $out = [];

        foreach ($jobs as $job) {
            if (! str_starts_with((string) ($job['created_at'] ?? ''), $day)) {
                continue;
            }

            $person = $job['created_by'] ?: 'Unassigned';
            $out[$person] = ($out[$person] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * The last ten digits, or null when there are not ten.
     *
     * A number that cannot be reduced to ten digits — a short internal
     * extension, a blocked caller — returns null and is treated as NEW rather
     * than being matched against a truncated key, which would pair unrelated
     * callers with each other.
     */
    private function digits(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
