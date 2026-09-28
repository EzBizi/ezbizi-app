<?php

namespace App\Support\Workiz;

use App\Models\SocialConnection;
use App\Support\Reports\Experimental\WorkizJobs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One branch's Workiz leads, read whole.
 *
 * The reader half of the Leads integration. The Leads module owns everything
 * inside itself — `Lead::SOURCE_WORKIZ`, the `leads.workiz.view` grant and its
 * backfill, the reply channel, the Unreplied rule and the source chip. This
 * class only fetches, because it is the half that holds the connections, the
 * nine accounts and the measurements.
 *
 * ── SAME SHAPE AS THE JOB READER, SAME REASONS ──────────────────
 *
 * Paged in hundreds (Workiz refuses `records=200` outright), short page means
 * last page, and no failure may be built from the request — the token is in the
 * path, so a message composed by cURL carries the credential. A quota refusal
 * arrives as `429 "Account reached Api Quotas"` and is this branch's failure,
 * not the run's.
 *
 * ── HALF OF WHAT THIS RETURNS MAY ALREADY BE IN EZBIZI ──────────
 *
 * Measured across 7,793 stored Workiz records: `JobSource` is `LS Ads` on 3,801
 * and `Thumbtack` on 89 — about 50% — and EzBizi already ingests both through
 * their own connected accounts. Importing wholesale double-counts the same
 * member of the public, and the unique key cannot catch it because the remote
 * ids come from different systems.
 *
 * That filter is NOT applied here. It is Jose's decision — filtering trades
 * duplicates for a hole in the history, since LS Ads enquiries older than
 * EzBizi's own feed exist nowhere else — and a reader that quietly dropped half
 * its rows would make his choice for him. `JobSource` is carried through so
 * whoever does decide can act on it.
 */
final class WorkizLeads
{
    /**
     * @return array{leads: list<array<string, mixed>>, failed: ?string}
     */
    public function raw(SocialConnection $connection, int $limit = 1000, ?string $since = null): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');

        if ($token === '') {
            return ['leads' => [], 'failed' => 'This branch has no stored token.'];
        }

        $leads = [];

        // `offset` is a PAGE NUMBER and every request asks for a full page:
        // measured on /job/all/ 2026-09-28 (see WorkizJobs::raw), where the
        // old `offset += 100` read one page and stopped. Leads come from the
        // same API; at A-1's volume (10 since January) no branch fills a page
        // today, which is why it never showed.
        for ($page = 0; count($leads) < $limit; $page++) {
            try {
                $response = Http::acceptJson()->get(
                    'https://api.workiz.com/api/v1/'.$token.'/lead/all/',
                    [
                        'start_date' => $since ?? now()->subYears(5)->toDateString(),
                        'offset' => $page,
                        'records' => WorkizJobs::PAGE,
                        'only_open' => 'false',
                    ],
                );
            } catch (ConnectionException) {
                return ['leads' => $leads, 'failed' => 'Workiz could not be reached.'];
            }

            if ($response->failed()) {
                return ['leads' => $leads, 'failed' => 'Workiz refused the lead list ('.$response->status().').'];
            }

            $rows = $response->json('data') ?? [];

            foreach ($rows as $lead) {
                if (is_array($lead)) {
                    $leads[] = $lead;
                }
            }

            if (count($rows) < WorkizJobs::PAGE) {
                break;
            }
        }

        return ['leads' => array_slice($leads, 0, $limit), 'failed' => null];
    }
}
