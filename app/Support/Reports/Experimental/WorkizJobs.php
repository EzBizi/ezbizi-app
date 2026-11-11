<?php

namespace App\Support\Reports\Experimental;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\User;
use App\Models\WorkizJob;
use App\Support\Reports\ReportView;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * Jobs booked since a date, read from EzBizi's synced copy (`workiz_jobs`,
     * kept current by ezbizi:sync-workiz) rather than from Workiz.
     *
     * For the Experimental report (Jose, 2026-10-06: "Experimental loading
     * slow"). Measured on the box: asking Workiz live took 5.7 s of every load
     * for 3,700 jobs; the copy matched it to one job on 2026-10-05 (310 vs
     * 309) and was synced within the minute. The copy's `payload` is the job
     * as Workiz sent it, so the same shape() reads both.
     *
     * @return array{jobs: list<array<string, mixed>>, synced_at: ?string}
     */
    public function stored(User $user, string $since): array
    {
        return $this->storedFrom($this->connections($user), $since);
    }

    /**
     * stored() across every Workiz account of the current organization, for
     * a scheduled run that has no person to scope by (ezbizi:ai-booked-no-job).
     *
     * @return array{jobs: list<array<string, mixed>>, synced_at: ?string}
     */
    /**
     * Every Workiz account of the organization, by name: Performance and
     * Scoring read all of them whoever holds the tab (Jose, 2026-10-15: people
     * assigned to A-1 Chimney "should be able to see it regardless").
     *
     * Each says whether the signed-in reader counts it (their Sources ticks).
     *
     * @return list<array{id: string, name: string, place: string, included: bool}>
     */
    public function allAccounts(): array
    {
        $excluded = self::excluded();

        return $this->inView()
            ->map(fn (SocialConnection $c) => [
                'id' => (string) $c->getKey(),
                'name' => (string) $c->display_name,
                'place' => $this->place($c),
                'included' => ! in_array((string) $c->getKey(), $excluded, true),
            ])
            ->values()->all();
    }

    /**
     * The accounts the reader counts: their unticked ones left out. $branches:
     * only those branches' accounts (a rule's place: A-1's dispatchers, not
     * the organization's 417 jobs, 2026-11-10).
     *
     * @param  list<string>|null  $branches
     */
    public function storedAll(string $since, ?array $branches = null): array
    {
        $excluded = self::excluded();

        return $this->storedFrom($this->inView()->reject(fn (SocialConnection $c) => in_array((string) $c->getKey(), $excluded, true))
            ->when($branches !== null, fn ($all) => $all->filter(fn (SocialConnection $c) => $c->scope_type === SocialConnection::SCOPE_FRANCHISE
                && in_array((string) $c->scope_id, $branches, true))), $since);
    }

    /** User preference key: the Workiz accounts this person leaves out of Reports. */
    public const EXCLUDED = 'reports.workiz_excluded';

    /**
     * The signed-in person's unticked accounts (2026-10-30, review item 12):
     * a viewing choice, never another person's. Nobody signed in (a
     * scheduled run): none.
     *
     * @return list<string>
     */
    public static function excluded(): array
    {
        $user = auth()->user();

        return $user instanceof User ? array_values(array_map('strval', (array) ($user->preferences[self::EXCLUDED] ?? []))) : [];
    }

    /** "Company · Branch" for a branch's account, else its name. */
    private function place(SocialConnection $c): string
    {
        if ($c->scope_type !== SocialConnection::SCOPE_FRANCHISE) {
            return (string) $c->display_name;
        }
        $branch = Franchise::query()->with('company')->find($c->scope_id);

        return $branch === null ? (string) $c->display_name
            : implode(' · ', array_filter([$branch->company?->name, $branch->name]));
    }

    /**
     * Every Workiz account of the organization, or only those of the branches
     * in the signed-in reader's view (ReportView, 2026-10-19).
     *
     * @return Collection<int, SocialConnection>
     */
    private function inView(): Collection
    {
        $branches = ReportView::franchiseIds();

        return SocialConnection::query()->where('provider', SocialProvider::Workiz->value)
            ->when($branches !== null, fn ($q) => $q->where('scope_type', SocialConnection::SCOPE_FRANCHISE)->whereIn('scope_id', $branches))
            ->get();
    }

    /** @param  Collection<int, SocialConnection>  $connections */
    private function storedFrom(Collection $connections, string $since): array
    {
        $connections = $connections->keyBy(fn (SocialConnection $c) => (string) $c->getKey());

        $rows = WorkizJob::query()
            ->whereIn('connection_id', $connections->keys()->all())
            ->where('booked_at', '>=', $since);

        $jobs = [];

        (clone $rows)->select(['id', 'connection_id', 'payload'])->lazyById(500)
            ->each(function (WorkizJob $row) use (&$jobs, $connections) {
                foreach ($this->shape([(array) $row->payload]) as $job) {
                    $jobs[] = ['account' => (string) $connections[(string) $row->connection_id]->display_name] + $job;
                }
            });

        return ['jobs' => $jobs, 'synced_at' => $rows->max('synced_at')];
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
     * A key from the v2 tab is refused by v1 (403, measured 2026-10-05) and
     * answered by v2, so the answer is which API the key speaks: what the
     * connection then records, and what every reader and writer branches on.
     *
     * @return 'v1'|'v2'
     *
     * @throws RuntimeException with a status and never a URL — the token is in
     *                          the path
     */
    public function verify(string $token): string
    {
        try {
            $response = Http::acceptJson()
                ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                    'start_date' => now()->subYear()->toDateString(),
                    'offset' => 0,
                    'records' => 1,
                    'only_open' => 'false',
                ]);

            if ($response->successful()) {
                return 'v1';
            }

            $v2 = WorkizV2::http($token)->get(WorkizV2::BASE.'/jobs', ['pageSize' => 1]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Workiz could not be reached.');
        }

        if ($v2->successful()) {
            return 'v2';
        }

        throw new RuntimeException('Workiz refused this token ('.$response->status().').');
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
     * that returns a short page drops out.
     *
     * VERIFIED AGAINST THE LIVE API 2026-09-15, both shapes in one process:
     *
     *     series   17 requests   840 rows   11.1s
     *     rounds   17 requests   840 rows    1.6s   in 2 rounds
     *
     * The equal ROW COUNT is the control and matters more than the time. Same
     * number of requests, same 840 rows, so the rounds dropped nothing — and a
     * speed-up that silently lost a page would look identical on the screen,
     * which is this report's whole failure mode. Timing alone would not have
     * told them apart.
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
        $v2 = [];

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
            $v2[$account] = WorkizV2::uses($connection);
        }

        /*
         * `offset` IS A PAGE NUMBER, not a row count - measured by the Workiz
         * session on production 2026-09-28 (Connecticut): offset=1 returned the
         * next 99 jobs and offset=100 returned none. This loop used to add
         * PAGE to it, so its second request asked for page 100, got nothing,
         * and every account over 100 jobs lost all but its first page. Page 0
         * is the FARTHEST-dated jobs, so what went missing was the near dates.
         *
         * `records` stays PAGE on every request: a smaller last page would
         * change the page size and move where page N starts.
         */
        for ($pageNumber = 0; $pageNumber * self::PAGE < $limit && $pending !== []; $pageNumber++) {
            $responses = Http::pool(function (Pool $pool) use ($pending, $pageNumber, $since, $v2) {
                foreach ($pending as $account => $token) {
                    $from = $since ?? now()->subYear()->toDateString();

                    if ($v2[$account]) {
                        $pool->as($account)->acceptJson()->withToken($token)->get(WorkizV2::BASE.'/jobs', self::v2Page($from, $pageNumber));

                        continue;
                    }

                    $pool->as($account)->acceptJson()->get(
                        'https://api.workiz.com/api/v1/'.$token.'/job/all/',
                        [
                            'start_date' => $from,
                            'offset' => $pageNumber,
                            'records' => self::PAGE,
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

                $page = array_values(array_filter($response->json('data') ?? [], 'is_array'));

                if ($v2[$account]) {
                    $page = array_map([WorkizV2::class, 'job'], $page);
                }

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
     * Every page of ONE connection's jobs, whole and unshaped.
     *
     * ── WHY THIS IS NOT `all()` WITH A FLAG ─────────────────────
     *
     * `all()` belongs to the Experimental report. Its return shape is pinned by
     * three tests in a file this session does not own
     * (`ExperimentalCallReportApiTest`), and `ExperimentalCallReportController`
     * does `array_column($accounts, 'name')` on `accounts()`. A flag that
     * widened `all()`'s payload would put the report's contract and the
     * importer's needs in one signature, and the next change to either would
     * have to reason about both.
     *
     * So: same paging, same "no failure may be built from the request" rule,
     * one connection at a time — because the importer enters one tenant per
     * connection and has no use for a cross-account fan-out.
     *
     * `$since` is not optional in spirit. The sweep runs every minute and
     * Workiz refuses with `429 "Account reached Api Quotas"` — measured on
     * A-1's own live sync, 26 refusals on 2026-09-23 alone at a two-minute
     * cadence. Reading only what changed is what makes a per-minute sweep
     * affordable; the five-year default exists for a first backfill and
     * nothing else.
     *
     * @return array{jobs: list<array<string, mixed>>, failed: ?string}
     */
    public function raw(SocialConnection $connection, int $limit = 1000, ?string $since = null): array
    {
        $jobs = [];

        // `offset` is a PAGE NUMBER, not a row count. Measured 2026-09-28 on
        // Connecticut (192 upcoming): offset=1 returned the next 99 jobs and
        // offset=100 returned none -- which is what this loop used to send, so
        // it read one page and stopped. Page 0 is the FARTHEST 100 by date:
        // every account with more than 100 upcoming jobs had its next days
        // unswept, and a booking for them was never seen at all.
        for ($page = 0; count($jobs) < $limit; $page++) {
            $read = $this->page($connection, $since ?? now()->subYears(5)->toDateString(), $page);
            array_push($jobs, ...$read['jobs']);

            if ($read['failed'] !== null) {
                return ['jobs' => $jobs, 'failed' => $read['failed']];
            }

            // A short page is the last page. `$limit` bounds the loop against an
            // API that ignored the offset and answered the same page for ever.
            if (count($read['jobs']) + ($read['skipped'] ?? 0) < self::PAGE) {
                break;
            }
        }

        // A job booked today with a date weeks back is outside a date window
        // (DMV 800020991, IL 400595749, 11-10): v2 also lists by created date.
        if (WorkizV2::uses($connection) && $since !== null) {
            for ($page = 0; $page < 20; $page++) {
                $read = $this->page($connection, $since, $page, by: 'created');

                if ($read['failed'] !== null) {
                    return ['jobs' => $jobs, 'failed' => $read['failed']];
                }

                array_push($jobs, ...$read['jobs']);

                if (count($read['jobs']) < self::PAGE) {
                    break;
                }
            }

            $jobs = array_values(collect($jobs)->unique(fn ($j) => $j['UUID'] ?? $j['id'] ?? json_encode($j))->all());
        }

        return ['jobs' => array_slice($jobs, 0, $limit), 'failed' => null];
    }

    /**
     * One page of `/job/all/`: the jobs dated `$since` or later, farthest first.
     *
     * Page 0 is the farthest future, and each page after it is older, down to
     * `$since` -- the LOWER bound on the job date (measured 2026-09-29 on
     * Georgia: since 2025-01-01 paged 25 pages back to October 2025 and still
     * had more). That is what reaches a job from before the mirror began.
     *
     * `$timeout` is Laravel's own 30 seconds unless a caller can afford more:
     * the minute sweep cannot, because the projection runs after it in the
     * same minute.
     *
     * @return array{jobs: list<array<string, mixed>>, failed: ?string}
     */
    public function page(SocialConnection $connection, string $since, int $page, int $timeout = 30, string $by = 'date'): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');

        if ($token === '') {
            return ['jobs' => [], 'failed' => 'This branch has no stored token.'];
        }

        if (WorkizV2::uses($connection)) {
            $read = WorkizV2::send($connection, 'GET', '/jobs', ['dateProperty' => $by] + self::v2Page($since, $page), $timeout);

            if ($read['status'] >= 500) {
                return $this->v2Around($connection, $since, $page, $timeout);
            }

            if ($read['refused'] !== null) {
                return ['jobs' => [], 'failed' => $read['refused'] === self::UNANSWERED ? self::UNANSWERED : 'Workiz refused the job list ('.$read['refused'].').'];
            }

            return ['jobs' => array_map([WorkizV2::class, 'job'], array_values(array_filter($read['data']['data'] ?? [], 'is_array'))), 'failed' => null];
        }

        try {
            $response = Http::acceptJson()->timeout($timeout)->get(
                'https://api.workiz.com/api/v1/'.$token.'/job/all/',
                [
                    'start_date' => $since,
                    'offset' => $page,
                    // Always a full page: if Workiz starts page N at
                    // N x records, a smaller last one would move it.
                    'records' => self::PAGE,
                    'only_open' => 'false',
                ],
            );
        } catch (ConnectionException) {
            // Never the exception's message: cURL composes it from the
            // request, and the token is in the URL.
            return ['jobs' => [], 'failed' => 'Workiz could not be reached.'];
        }

        if ($response->failed()) {
            return ['jobs' => [], 'failed' => 'Workiz refused the job list ('.$response->status().').'];
        }

        return ['jobs' => array_values(array_filter($response->json('data') ?? [], 'is_array')), 'failed' => null];
    }

    /**
     * A v2 page Workiz answers 500 for, read in tens and then one by one, so
     * one bad job does not hide the 99 beside it. v2 answers 500 for any list
     * page holding one New Jersey job (measured 2026-10-05). The jobs it
     * cannot read are skipped and logged by position; the rest come back.
     *
     * @return array{jobs: list<array<string, mixed>>, failed: ?string}
     */
    private function v2Around(SocialConnection $connection, string $since, int $page, int $timeout): array
    {
        $jobs = [];
        $skipped = [];
        $read = fn (int $index, int $size) => WorkizV2::send($connection, 'GET', '/jobs',
            ['page' => $index, 'pageSize' => $size] + self::v2Page($since, 0), $timeout);

        for ($ten = $page * 10 + 1; $ten <= ($page + 1) * 10; $ten++) {
            $tens = $read($ten, 10);

            if ($tens['refused'] === null) {
                $rows = array_values(array_filter($tens['data']['data'] ?? [], 'is_array'));
                array_push($jobs, ...array_map([WorkizV2::class, 'job'], $rows));

                if (count($rows) < 10) {
                    break;
                }

                continue;
            }

            if ($tens['status'] < 500) {
                return ['jobs' => $jobs, 'failed' => 'Workiz refused the job list ('.$tens['refused'].').'];
            }

            for ($one = ($ten - 1) * 10 + 1; $one <= $ten * 10; $one++) {
                $single = $read($one, 1);

                if ($single['refused'] !== null) {
                    $skipped[] = $one;

                    continue;
                }

                array_push($jobs, ...array_map([WorkizV2::class, 'job'], array_values(array_filter($single['data']['data'] ?? [], 'is_array'))));
            }
        }

        if ($skipped !== []) {
            Log::warning('Workiz v2: '.$connection->display_name.': skipped job(s) at position '.implode(', ', $skipped).' that Workiz cannot list.');
        }

        // `skipped` keeps "a short page is the last page" true for the caller.
        return ['jobs' => $jobs, 'failed' => null, 'skipped' => count($skipped)];
    }

    /**
     * v1's page `$page` (from 0) of the jobs dated `$since` or later, asked of
     * v2: pages from 1, the same lower bound on the job's own date, and the
     * oldest-made first, so a page a resumed backfill has read stays read while
     * new jobs land at the end.
     *
     * @return array<string, mixed>
     */
    private static function v2Page(string $since, int $page): array
    {
        return [
            'page' => $page + 1, 'pageSize' => self::PAGE,
            'dateProperty' => 'date', 'dateOperator' => 'next', 'date' => $since,
            'field' => 'created', 'order' => 'ASC',
        ];
    }

    /**
     * One job, by its Workiz id, as Workiz holds it NOW.
     *
     * ── WHY THE SWEEP ALONE CANNOT KEEP THE MIRROR TRUE ─────────
     *
     * The sweep reads `/job/all/` from a date two days back, so once a job's
     * date passes that window the sweep never sees it again, and it stays at
     * whatever status it last had. (`/job/all/` itself can reach the past: see
     * page(). The sweep's window is the choice, made for the quota.) On
     * production 2026-09-24, 1,323 past-dated mirror rows still read as open;
     * asked about three, Workiz said Done, done pending approval, and — only
     * one of them — still Submitted.
     *
     * `/job/get/{UUID}/` answers for a past job, which makes it the only way to
     * learn that one has closed.
     *
     * Same rule as `raw()`: no failure message is built from the request,
     * because the token is in the path.
     *
     * @return array{job: ?array<string, mixed>, failed: ?string}
     */
    public function find(SocialConnection $connection, string $uuid): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');

        if (WorkizV2::uses($connection)) {
            // v2 answers 404 to the six-character uuid (measured): it reads a
            // job by its JOB- id, which the mirror keeps under `v2`.
            // The payload itself: Eloquent's value() cannot read a JSON path.
            $id = str_starts_with($uuid, 'JOB-') ? $uuid : (WorkizJob::query()->withoutGlobalScopes()
                ->where('franchise_id', $connection->scope_id)->where('remote_id', $uuid)->first(['payload'])?->payload['v2']['id'] ?? null);

            if (is_string($id) && $id !== '') {
                $read = WorkizV2::send($connection, 'GET', '/jobs/'.rawurlencode($id));

                return match (true) {
                    $read['missing'] => ['job' => null, 'failed' => null],
                    $read['refused'] !== null => ['job' => null, 'failed' => $read['refused']],
                    default => ['job' => WorkizV2::job((array) $read['data']), 'failed' => null],
                };
            }

            // A row v1 landed before the switch has no JOB- id. The v1 pair
            // kept beside the key still asks for it by uuid, and answers 204
            // when Workiz deleted it: what retires a job deleted before v2
            // ever read it (Nationwide's 45 moved to Massachusetts, 2026-10-05).
            $token = (string) ($connection->credentials['v1']['api_token'] ?? '');

            if ($token === '') {
                return ['job' => null, 'failed' => 'EzBizi holds no Workiz v2 id for job '.$uuid.'.'];
            }
        }

        if ($token === '') {
            return ['job' => null, 'failed' => 'This branch has no stored token.'];
        }

        try {
            $response = Http::acceptJson()->get(
                'https://api.workiz.com/api/v1/'.$token.'/job/get/'.rawurlencode($uuid).'/'
            );
        } catch (ConnectionException) {
            return ['job' => null, 'failed' => 'Workiz could not be reached.'];
        }

        if ($response->failed()) {
            return ['job' => null, 'failed' => 'Workiz refused the job ('.$response->status().').'];
        }

        // A list with one job in it, measured — though a bare object is
        // accepted too, because the documented shape and the real one have
        // disagreed before.
        $data = $response->json('data');
        $job = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;

        return ['job' => is_array($job) ? $job : null, 'failed' => null];
    }

    /**
     * A WRITE to one job -- /job/update/, /job/assign/ or /job/unassign/.
     *
     * Needs the account's SECOND key, `auth_secret`, in the JSON body beside
     * the token in the path. The nine were copied from A1 Tools on 2026-09-25
     * with Jose's OK. Measured that day on the test job 99QWJ0: a write answers
     * with no job in it, so a caller that wants the result re-reads with find().
     *
     * Null when Workiz accepted it. Otherwise Workiz's own sentence -- "Cannot
     * assign Chaim Perez to job 99QWJ0, User is not assigned to select service
     * area." -- because that is what a dispatcher can act on. Same rule as
     * raw(): nothing is built from the request, whose path holds the token.
     *
     * @param  array<string, mixed>  $body
     */
    public function post(SocialConnection $connection, string $path, array $body): ?string
    {
        return $this->send($connection, $path, $body)['refused'];
    }

    public const UNANSWERED = 'Workiz could not be reached.';

    /**
     * post(), plus Workiz's `data` when it accepts: a create's answer is the
     * only place the new job's UUID is given.
     *
     * UNANSWERED is the one refusal that does not mean "nothing changed": a
     * timeout is a ConnectionException too, and Workiz may have done the work
     * before the answer was lost.
     *
     * @param  array<string, mixed>  $body
     * @return array{data: mixed, refused: ?string}
     */
    public function send(SocialConnection $connection, string $path, array $body): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');
        $secret = (string) ($connection->credentials['auth_secret'] ?? '');

        if ($token === '' || $secret === '') {
            return ['data' => null, 'refused' => 'This branch has no Workiz write key, so EzBizi cannot change its jobs.'];
        }

        try {
            $response = Http::acceptJson()->asJson()->post(
                'https://api.workiz.com/api/v1/'.$token.$path,
                ['auth_secret' => $secret] + $body,
            );
        } catch (ConnectionException) {
            return ['data' => null, 'refused' => self::UNANSWERED];
        }

        if ($response->successful()) {
            return ['data' => $response->json('data'), 'refused' => null];
        }

        // A validation refusal names its fields under `details`, measured by
        // A1 Tools: {"msg":"Error Validating Fields","details":{"Comments":"Invalid
        // Field"}}. The message alone would not say which one.
        $details = $response->json('details');
        $fields = is_array($details) && ! isset($details['error'])
            ? implode('; ', array_map(fn ($field, $why) => $field.': '.(is_scalar($why) ? $why : json_encode($why)), array_keys($details), $details))
            : '';

        $said = (string) ($response->json('msg') ?: 'Workiz refused the change ('.$response->status().').');

        return ['data' => null, 'refused' => (string) ($response->json('details.error') ?: ($fields === '' ? $said : rtrim($said, '.').': '.$fields))];
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
                // A client's other number: 10 of 43 "AI heard a booking, no job"
                // calls (Oct 27 - Nov 2) came from it (Trey, 2026-11-03).
                'second_phone' => $job['SecondPhone'] ?? null,
                'created_by' => $job['CreatedBy'] ?? null,

                // CreatedDate is when the job was BOOKED; JobDateTime is when
                // the van turns up, and live rows put them up to a year apart.
                // The earlier draft read JobDateTime as created_at, which would
                // have matched today's calls against today's APPOINTMENTS - a
                // different set of jobs entirely, and one that looks plausible.
                'created_at' => $job['CreatedDate'] ?? null,
                'scheduled_for' => $job['JobDateTime'] ?? null,

                'status' => $job['Status'] ?? null,
                'tags' => $job['Tags'] ?? [],
            ];
        }

        return $out;
    }
}
