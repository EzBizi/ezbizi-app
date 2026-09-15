<?php

namespace App\Support\Reports\Experimental;

use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
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
 * THE RULE, rather than a count of the ways it can be broken: no failure may
 * produce a message built from the REQUEST. A status is safe; anything cURL or
 * Guzzle composed is not, because it carries the URL and the token is in it.
 *
 * Stated that way on purpose. This comment twice said a number and was twice
 * made wrong by the next change:
 *
 *   1. The first version guarded a response that came back and FAILED.
 *   2. The first live run threw `ConnectionException` instead — no response
 *      to inspect, and a cURL message carrying the whole URL. I fixed it and
 *      wrote "both paths are caught now".
 *   3. Then I made `all()` concurrent, and `Http::pool` RETURNS the exception
 *      object where a response would be rather than raising it. It walked past
 *      both existing guards, and "both" had been false since that commit —
 *      written by me, in the same hour, without noticing.
 *
 * Which is why the guard is now `instanceof Response` first, everywhere, and
 * why this paragraph names the property instead of tallying the branches: a
 * total is a claim about the paths somebody had FOUND, and the next library
 * behaviour is not in it. The original lesson stands and generalises —
 * "nothing here logs a URL" was a claim about the code I had written, not
 * about the code that runs.
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
     * THE ID TRAVELS WITH THE NAME, and that is not decoration. Workiz is a
     * `dataSources()` provider, and `SocialConnectionsController::index`
     * deliberately excludes those - listing Google Ads accounts there once put
     * twenty-two of them in the composer as if a post could go to one. So a
     * connected Workiz account appears in NO shared list, and this payload is
     * the only place its id is published. Without it, ten credentials that can
     * act on A-1's jobs would be connectable and not removable, which is the
     * entry-point failure wearing its exit as a hat.
     *
     * @return list<array{id: string, name: string}>
     */
    public function accounts(User $user): array
    {
        return $this->connections($user)
            ->map(fn (SocialConnection $c) => [
                'id' => (string) $c->getKey(),
                'name' => (string) $c->display_name,
            ])
            ->all();
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
     * Every connected branch, fetched CONCURRENTLY, in rounds of one page each.
     *
     * -- Why concurrency here and removal on the Ads screen --------
     *
     * The Google Ads tab had the same shape - 53 accounts in series, 47
     * seconds - and the Leads session fixed it by DELETING the fan-out: one
     * account at a time, picked by the reader. That was right there and is
     * wrong here, and the test that separates them is theirs: does the slow
     * thing spend a rationed EXTERNAL quota, or is it merely slow?
     *
     * Ads spends 106 operations of a 15,000/day Google quota, shared with the
     * lead sync that is the actual product. Parallelising would have spent
     * them faster rather than fewer.
     *
     * Measured here 2026-09-15: Workiz advertises NO rate-limit header at all,
     * and 17 requests take 9.2s of this screen's ~12. There is no quota to
     * conserve, so the only cost is wall-clock.
     *
     * And removal is not available to this report even if it were cheaper. The
     * accounts are DISJOINT and the same people book into all of them, so a
     * one-account view does not narrow a person's number, it makes it WRONG.
     * That is the failure the coverage line exists to prevent.
     *
     * -- Rounds, because the page count is not knowable up front ---
     *
     * Paging is by offset and the last page is identified by being short, so
     * the number of requests cannot be computed before making them. Each round
     * asks every still-hungry account for its next page at once; an account
     * that returns a short page drops out. Nine accounts over ~840 rows settle
     * in two rounds.
     *
     * RingCentral is deliberately NOT parallelised alongside this: its call log
     * answers `X-Rate-Limit-Group: heavy, limit 10, window 60`, so it is the
     * half of this screen with a quota worth respecting.
     *
     * A failure on ONE branch does not lose the others: it is recorded against
     * that branch and the rest still answer.
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
        $pending = [];

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

            $pending[$account] = $token;
        }

        for ($offset = 0; $offset < $limit && $pending !== []; $offset += self::PAGE) {
            $responses = Http::pool(function (Pool $pool) use ($pending, $offset, $limit, $since) {
                foreach ($pending as $account => $token) {
                    $pool->as($account)->acceptJson()->get(
                        'https://api.workiz.com/api/v1/'.$token.'/job/all/',
                        [
                            'start_date' => $since ?? now()->subYear()->toDateString(),
                            'offset' => $offset,
                            'records' => min(self::PAGE, $limit - $offset),
                            'only_open' => 'false',
                        ],
                    );
                }
            });

            foreach (array_keys($pending) as $account) {
                $response = $responses[$account] ?? null;

                /*
                 * A POOLED FAILURE ARRIVES AS A VALUE, NOT A THROW.
                 *
                 * `Http::pool` hands back the exception object in place of a
                 * response rather than raising it, so a `catch` would never run
                 * and an unchecked `->failed()` on a ConnectionException is a
                 * fatal. The sequential version learned the hard way that a
                 * connection failure carries the whole URL - and the token is
                 * IN that URL - so nothing here lets one reach a message.
                 */
                if (! $response instanceof Response) {
                    $failed[$account] = 'Workiz could not be reached.';
                    unset($pending[$account]);

                    continue;
                }

                if ($response->failed()) {
                    // Status only, for the same reason.
                    $failed[$account] = 'Workiz refused the job list ('.$response->status().').';
                    unset($pending[$account]);

                    continue;
                }

                $page = $response->json('data') ?? [];

                foreach ($this->shape($page) as $job) {
                    $jobs[] = ['account' => $account] + $job;
                }

                // A short page is the last page. Stopping on count alone would
                // loop forever against an API that ignored the offset.
                if (count($page) < self::PAGE) {
                    unset($pending[$account]);
                }
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
     * One page of Workiz rows, reduced to what this report reads.
     *
     * @param  list<array<string, mixed>>  $page
     * @return list<array{phone: ?string, created_by: ?string, created_at: ?string, scheduled_for: ?string, status: ?string}>
     */
    private function shape(array $page): array
    {
        $out = [];

        foreach ($page as $job) {
            $out[] = [
                // CreatedBy is a person's NAME - "Trey Brannan", "Sasha
                // Solano" - which is what the per-member report attributes on,
                // and the names match EzBizi users. It is NULL on 21 of the
                // first 900 live rows, so "nobody recorded" is a real bucket
                // and not an impossible case to skip.
                'phone' => $job['Phone'] ?? null,
                'created_by' => $job['CreatedBy'] ?? null,

                // CreatedDate is when the job was BOOKED; JobDateTime is when
                // the van turns up, and live rows put them up to a year apart.
                // The earlier draft read JobDateTime as created_at, which would
                // have matched today's calls against today's APPOINTMENTS - a
                // different set of jobs entirely, and one that looks plausible.
                'created_at' => $job['CreatedDate'] ?? null,
                'scheduled_for' => $job['JobDateTime'] ?? null,

                'status' => $job['Status'] ?? null,
            ];
        }

        return $out;
    }
}
