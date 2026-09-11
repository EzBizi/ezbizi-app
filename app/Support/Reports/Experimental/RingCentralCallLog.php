<?php

namespace App\Support\Reports\Experimental;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * One day of calls from RingCentral (TEMPORARY — see config/experimental_reports.php).
 *
 * ── The account endpoint, not the extension one ──────────────────
 *
 * `/account/~/extension/~/call-log` returns only the AUTHENTICATED user's own
 * calls, which for a server app is the app's own extension and therefore
 * nothing anybody wants. `/account/~/call-log` returns the whole account, which
 * is the only way to answer "how many calls did each person take". Verified
 * against RingCentral's own guide, 2026-09-11.
 *
 * ── `view=Detailed` is what makes the question answerable ────────
 *
 * The Simple view gives one record per call. Detailed gives the LEGS, which is
 * how a queue call that rang four people and was answered by one can be
 * attributed to the one who answered rather than to all four or to none.
 *
 * ── Absent credentials are a state, not an error ─────────────────
 *
 * `configured()` is separate from `forDate()` so the screen can say "not
 * connected" rather than draw an empty report. An empty report and a missing
 * connection look identical and mean opposite things.
 */
final class RingCentralCallLog
{
    /** RingCentral keeps 12 months of call log; older dates return nothing. */
    public const RETENTION_MONTHS = 12;

    public function configured(): bool
    {
        $config = config('experimental_reports.ringcentral');

        return filled($config['client_id'])
            && filled($config['client_secret'])
            && filled($config['jwt']);
    }

    /**
     * Every call leg for one day, in the account's own timezone.
     *
     * @return list<array{
     *     extension: ?string, person: ?string, direction: ?string,
     *     result: ?string, from: ?string, to: ?string, started_at: ?string
     * }>
     */
    public function forDate(string $from, string $to): array
    {
        $token = $this->token();
        $server = rtrim((string) config('experimental_reports.ringcentral.server'), '/');

        $records = [];
        $page = 1;

        do {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get($server.'/restapi/v1.0/account/~/call-log', [
                    'view'     => 'Detailed',
                    'dateFrom' => $from,
                    'dateTo'   => $to,
                    'perPage'  => 1000,
                    'page'     => $page,
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'RingCentral refused the call log: '.$response->status().' '.$response->body()
                );
            }

            $body = $response->json();

            foreach ($body['records'] ?? [] as $record) {
                $records[] = $this->leg($record);
            }

            // `paging` carries the page count; stop when it says so rather than
            // when a page comes back short, which is also true of the last page
            // and would stop one page early on an exact multiple.
            $pages = (int) ($body['paging']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $pages);

        return $records;
    }

    /** @return array{extension: ?string, person: ?string, direction: ?string, result: ?string, from: ?string, to: ?string, started_at: ?string} */
    private function leg(array $record): array
    {
        // Who the call reached. On an inbound call that is `to`; the extension
        // block is only present when a leg actually landed on one, which is
        // exactly the calls this report counts.
        $extension = $record['to']['extensionId']
            ?? $record['to']['extensionNumber']
            ?? null;

        return [
            'extension'  => $extension === null ? null : (string) $extension,
            'person'     => $record['to']['name'] ?? null,
            'direction'  => $record['direction'] ?? null,
            'result'     => $record['result'] ?? null,
            'from'       => $record['from']['phoneNumber'] ?? null,
            'to'         => $record['to']['phoneNumber'] ?? null,
            'started_at' => $record['startTime'] ?? null,
        ];
    }

    private function token(): string
    {
        $config = config('experimental_reports.ringcentral');
        $server = rtrim((string) $config['server'], '/');

        $response = Http::asForm()
            ->withBasicAuth((string) $config['client_id'], (string) $config['client_secret'])
            ->post($server.'/restapi/oauth/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => (string) $config['jwt'],
            ]);

        if ($response->failed()) {
            // Deliberately does NOT include the body: a failed token exchange
            // echoes back parts of what was sent.
            throw new RuntimeException('RingCentral refused the credentials ('.$response->status().').');
        }

        return (string) $response->json('access_token');
    }
}
