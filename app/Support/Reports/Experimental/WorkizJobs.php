<?php

namespace App\Support\Reports\Experimental;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Workiz jobs, for two questions (TEMPORARY).
 *
 * 1. Is this caller an EXISTING client — does their number appear on any job
 *    already? Workiz's official API has no client-list endpoint worth relying
 *    on, so "existing" is derived from job history. That is a PROXY and the
 *    screen says so: somebody in the client list who has never had a job books
 *    as new.
 * 2. How many jobs did each person create on the day, which is the only thing
 *    Workiz can be asked that is comparable to a call count.
 *
 * ── The token goes in the PATH, which is why nothing here logs a URL ──
 *
 * `api.workiz.com/api/v1/{token}/job/all/`. A header can be redacted by a
 * logger that knows about headers; a URL ends up in far more places and none
 * of them know. So failures report a status and never a URL or a body.
 *
 * ── ONE TOKEN PER ACCOUNT, SO THIS READS SEVERAL ────────────────
 *
 * Workiz: "Each key is specific to a Workiz account, not an individual user."
 * A-1 runs Nationwide plus nine state sub-accounts, so a reader built on one
 * token would return real rows and silently omit eight states.
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
     *     jobs: list<array{account: string, phone: ?string, created_by: ?string, created_at: ?string, status: ?string}>,
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

    /** @return list<array{phone: ?string, created_by: ?string, created_at: ?string, status: ?string}> */
    private function forAccount(string $token, int $limit): array
    {
        $response = Http::acceptJson()
            ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                'start_date' => now()->subYear()->toDateString(),
                'offset'     => 0,
                'records'    => $limit,
                'only_open'  => 'false',
            ]);

        if ($response->failed()) {
            // Status only. The token is IN the path, so the URL must never reach
            // a log, an exception message or a bug report.
            throw new RuntimeException('Workiz refused the job list ('.$response->status().').');
        }

        $out = [];

        foreach ($response->json('data') ?? [] as $job) {
            $out[] = [
                'phone'      => $job['Phone'] ?? null,
                'created_by' => $job['CreatedBy'] ?? null,
                'created_at' => $job['JobDateTime'] ?? $job['CreatedDate'] ?? null,
                'status'     => $job['Status'] ?? null,
            ];
        }

        return $out;
    }
}
