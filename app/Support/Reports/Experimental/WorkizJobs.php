<?php

namespace App\Support\Reports\Experimental;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Workiz jobs, for one question (TEMPORARY).
 *
 * How many jobs did each person BOOK on the day — the only thing Workiz can be
 * asked that is comparable to a call count.
 *
 * It used to be asked a second question, "is this caller an existing client",
 * answered by looking for their number on an older job. That is gone: A-1
 * already routes new and existing callers through separate RingCentral queues,
 * so the answer was on the call all along and the job-history version was a
 * guess standing in for a fact somebody had already recorded.
 *
 * ── The token goes in the PATH, which is why nothing here logs a URL ──
 *
 * `api.workiz.com/api/v1/{token}/job/all/`. A header can be redacted by a
 * logger that knows about headers; a URL ends up in far more places and none
 * of them know. So failures report a status and never a URL or a body.
 *
 * That guard was incomplete until the first live run: it only covered a
 * response that came back and FAILED. A connection that never completes
 * throws `ConnectionException`, whose message is built by cURL and contains
 * the full URL — token included. Both paths are caught now. The lesson is
 * that "nothing here logs a URL" was a claim about the code I had written,
 * not about the code that runs.
 *
 * ── ONE TOKEN PER ACCOUNT, SO THIS READS SEVERAL ────────────────
 *
 * Workiz: "Each key is specific to a Workiz account, not an individual user."
 * A-1 runs Nationwide plus nine state sub-accounts, and they are DISJOINT —
 * measured 2026-09-14, each token 204s on the other's jobs and the two books
 * share no UUIDs. So a reader built on one token returns real rows and
 * silently omits nine accounts.
 *
 * `accounts()` therefore returns which accounts are configured, and `all()`
 * reads every one of them and TAGS each job with the account it came from. The
 * screen can then say which accounts a number covers — because "120 calls" that
 * silently means one state is worse than no number at all.
 */
final class WorkizJobs
{
    public function configured(): bool
    {
        return $this->accounts() !== [];
    }

    /**
     * The Workiz accounts this deployment holds a key for.
     *
     * Named rather than counted, because a report covering Nationwide and NJ is
     * a different fact from one covering all ten, and the screen has to be able
     * to say which.
     *
     * @return list<string>
     */
    public function accounts(): array
    {
        return array_keys((array) config('experimental_reports.workiz.accounts', []));
    }

    /**
     * Jobs from every configured account, each tagged with where it came from.
     *
     * A failure on ONE account does not lose the others: it is recorded against
     * that account and the rest still answer. Losing nine states because one key
     * was rotated would be the silently-partial failure this class exists to
     * avoid, wearing a different hat.
     *
     * @return array{
     *     jobs: list<array{account: string, phone: ?string, created_by: ?string, created_at: ?string, scheduled_for: ?string, status: ?string}>,
     *     failed: array<string, string>
     * }
     */
    public function all(int $limit = 1000): array
    {
        $jobs = [];
        $failed = [];

        foreach ((array) config('experimental_reports.workiz.accounts', []) as $account => $token) {
            try {
                foreach ($this->forAccount((string) $token, $limit) as $job) {
                    $jobs[] = ['account' => (string) $account] + $job;
                }
            } catch (RuntimeException $e) {
                $failed[(string) $account] = $e->getMessage();
            }
        }

        if ($jobs === [] && $failed !== []) {
            throw new RuntimeException(
                'Workiz refused every account: '.implode('; ', array_keys($failed))
            );
        }

        return ['jobs' => $jobs, 'failed' => $failed];
    }

    /**
     * Workiz caps a page at 100 and REFUSES more.
     *
     * Measured against the live API on 2026-09-14: `records=200` returns
     * `400 Invalid paging parameters {"records":"invalid"}`. My first draft
     * asked for 1000 in one call and would have failed outright on every run -
     * a defect that no test caught, because every fixture here is invented and
     * a stub happily returns whatever it is told to.
     */
    public const PAGE = 100;

    /**
     * `start_date` FILTERS THE APPOINTMENT, NOT THE BOOKING.
     *
     * Measured 2026-09-14: asked with `start_date=2026-09-01`, the API returned
     * a job whose `CreatedDate` is 2025-10-29 — ten months before the window —
     * because its `JobDateTime` is 2026-10-22. The list is ordered by
     * `JobDateTime` descending and the filter is on the same field.
     *
     * So Workiz CANNOT be asked "which jobs were booked on this day", which is
     * the only question this report has. The day has to be filtered here, from
     * `created_at`, over a window wide enough to contain every appointment that
     * a job booked that day could have been given.
     *
     * @return list<array{phone: ?string, created_by: ?string, created_at: ?string, scheduled_for: ?string, status: ?string}>
     */
    private function forAccount(string $token, int $limit): array
    {
        $out = [];

        for ($offset = 0; $offset < $limit; $offset += self::PAGE) {
            try {
                $response = Http::acceptJson()
                    ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                        'start_date' => now()->subYear()->toDateString(),
                        'offset' => $offset,
                        'records' => min(self::PAGE, $limit - $offset),
                        'only_open' => 'false',
                    ]);
            } catch (ConnectionException $e) {
                // MEASURED, and it defeated the guard below. A connection
                // failure throws BEFORE there is a response to inspect, and
                // Guzzle puts the whole URL in the message — so the first live
                // run printed the Nationwide token into a stack trace. The
                // status-only rule was written for the wrong failure.
                throw new RuntimeException('Workiz could not be reached.');
            }

            if ($response->failed()) {
                // Status only. The token is IN the path, so the URL must never
                // reach a log, an exception message or a bug report.
                throw new RuntimeException('Workiz refused the job list ('.$response->status().').');
            }

            $page = $response->json('data') ?? [];

            foreach ($page as $job) {
                $out[] = [
                    // CreatedBy is a person's NAME - "Trey Brannan", "Sasha
                    // Solano" - which is what the per-member report attributes
                    // on, and the names match EzBizi users. It is NULL on 21 of
                    // the first 900 live rows, so "nobody recorded" is a real
                    // bucket and not an impossible case to skip.
                    'phone' => $job['Phone'] ?? null,
                    'created_by' => $job['CreatedBy'] ?? null,

                    // CreatedDate is when the job was BOOKED; JobDateTime is
                    // when the van turns up, and live rows put them up to a
                    // year apart. The earlier draft read JobDateTime as
                    // created_at, which would have matched today's calls
                    // against today's APPOINTMENTS - a different set of jobs
                    // entirely, and one that looks plausible.
                    'created_at' => $job['CreatedDate'] ?? null,
                    'scheduled_for' => $job['JobDateTime'] ?? null,

                    'status' => $job['Status'] ?? null,
                ];
            }

            // A short page is the last page. Stopping on count alone would loop
            // forever against an API that ignored the offset.
            if (count($page) < self::PAGE) {
                break;
            }
        }

        return $out;
    }
}
