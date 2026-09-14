<?php

namespace App\Support\Reports\Experimental;

use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
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
 * ── ONE TOKEN PER BRANCH, SO THIS READS SEVERAL ─────────────────
 *
 * Workiz: "Each key is specific to a Workiz account, not an individual user."
 * A-1 runs ten accounts and they are DISJOINT — measured 2026-09-14, each token
 * answers 204 for another account's job, with that account's own job as the
 * control. So a reader built on one token returns real rows and silently omits
 * nine books. Worse than regional: the same people book into all ten, so one
 * token gets every person's number wrong rather than missing some places.
 *
 * `accounts()` therefore returns which branches are connected, BY NAME, and
 * `all()` reads every one and tags each job with the branch it came from. A
 * number that silently covers one branch is worse than no number.
 *
 * ── THE TOKENS LIVE ON BRANCHES NOW, NOT IN AN ENV VAR ──────────
 *
 * Jose ruled it: "connect them to each of their designated franchises." So the
 * source is `social_connections` rows scoped with `scope_type = franchise`,
 * exactly as Google Ads and GBP are stored — NOT `leads_franchise_id`, which
 * answers where a connection's ENQUIRIES get assigned and is a different
 * question. The Google API session ruled that; their migration had already
 * separated the two.
 *
 * Reading is filtered through `SocialConnectionAccess::inView`, because this
 * runs inside a REQUEST. `SyncLeads` deliberately does the opposite and says
 * why — a scheduled sweep that borrowed the request filter would sync nothing.
 * The consequence here is that the report covers what the reader's current view
 * covers: ten branches from the organization, one from a branch view. Which is
 * why the screen names them.
 */
final class WorkizJobs
{
    public function __construct(private readonly SocialConnectionAccess $access) {}

    public function configured(User $user): bool
    {
        return $this->accounts($user) !== [];
    }

    /**
     * The branches this reader can see a Workiz account for.
     *
     * Named rather than counted, because a report covering two branches is a
     * different fact from one covering ten, and the screen has to be able to
     * say which.
     *
     * @return list<string>
     */
    public function accounts(User $user): array
    {
        return array_values($this->connections($user)->pluck('display_name')->all());
    }

    /**
     * Connected Workiz accounts this person may see in their current view.
     *
     * @return Collection<int, SocialConnection>
     */
    private function connections(User $user): Collection
    {
        return SocialConnection::query()
            ->where('provider', SocialProvider::Workiz->value)
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();
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
    public function all(User $user, int $limit = 1000, ?string $since = null): array
    {
        $jobs = [];
        $failed = [];

        foreach ($this->connections($user) as $connection) {
            $account = (string) $connection->display_name;
            $token = (string) ($connection->credentials['api_token'] ?? '');

            if ($token === '') {
                // A row with no token is a connection somebody made and
                // something lost. Recorded by name rather than skipped, because
                // a branch silently absent from the count is the failure this
                // class exists to avoid.
                $failed[$account] = 'This branch has no stored token.';

                continue;
            }

            try {
                foreach ($this->forAccount($token, $limit, $since) as $job) {
                    $jobs[] = ['account' => $account] + $job;
                }
            } catch (RuntimeException $e) {
                $failed[$account] = $e->getMessage();
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
     * Does Workiz accept this token? Asked BEFORE a row is written.
     *
     * A typo pasted into a connect form is a field-level refusal the person can
     * fix, not a connection that looks made and returns nothing days later.
     * Mailchimp's connect flow verifies for the same reason.
     *
     * One job requested rather than none: `records=0` is not a documented way
     * to ask "are you there", and an empty page is a valid answer for a new
     * account, so this proves the CREDENTIAL rather than the data.
     *
     * @throws RuntimeException with a status and never a URL — the token is in
     *                          the path
     */
    public function verify(string $token): void
    {
        try {
            $response = Http::acceptJson()
                ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                    'start_date' => now()->subYear()->toDateString(),
                    'offset' => 0,
                    'records' => 1,
                    'only_open' => 'false',
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Workiz could not be reached.');
        }

        if ($response->failed()) {
            throw new RuntimeException('Workiz refused this token ('.$response->status().').');
        }
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
    private function forAccount(string $token, int $limit, ?string $since = null): array
    {
        $out = [];

        for ($offset = 0; $offset < $limit; $offset += self::PAGE) {
            try {
                $response = Http::acceptJson()
                    ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                        'start_date' => $since ?? now()->subYear()->toDateString(),
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
