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
 */
final class WorkizJobs
{
    public function configured(): bool
    {
        return filled(config('experimental_reports.workiz.token'));
    }

    /**
     * Jobs Workiz holds, newest first.
     *
     * @return list<array{phone: ?string, created_by: ?string, created_at: ?string, status: ?string}>
     */
    public function all(int $limit = 1000): array
    {
        $token = (string) config('experimental_reports.workiz.token');

        $response = Http::acceptJson()
            ->get('https://api.workiz.com/api/v1/'.$token.'/job/all/', [
                'start_date' => now()->subYear()->toDateString(),
                'offset'     => 0,
                'records'    => $limit,
                'only_open'  => 'false',
            ]);

        if ($response->failed()) {
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
