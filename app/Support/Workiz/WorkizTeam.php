<?php

namespace App\Support\Workiz;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One branch's Workiz team, read whole.
 *
 * ── THIS IS THE ONE PART NOT MEASURED AGAINST A CAPTURED PAYLOAD ──
 *
 * Every other Workiz shape in this codebase was checked against real stored
 * responses in `a1tools_workiz_jobs.job_data`. **Team has no such corpus**:
 * A1 Tools stores team data flattened in `a1tools_workiz_team_map` and keeps no
 * raw payload, so there is nothing to read back.
 *
 * What the field names here rest on instead, stated so the next person knows
 * which half is solid:
 *
 *  - `id`, `name`, `email`, `role`, `active` — corroborated by A1's live
 *    `a1tools_workiz_team_map` columns (`workiz_user_id`, `workiz_name`,
 *    `workiz_email`, `workiz_role`, `workiz_active`), written by code that has
 *    been calling this endpoint for months. Five of eight fields.
 *  - `fieldTech`, `serviceAreas`, `skills` — **published spec only.** A1 does
 *    not store them, so nothing corroborates them, and the published spec has
 *    already been wrong in both directions about the Job shape.
 *
 * Which is why the whole response is kept in `payload`: if a name is wrong, the
 * data is still there and the fix is a mapper change rather than a re-sync.
 *
 * `/team/all/` takes no parameters and is not paged, per A1's own client.
 */
final class WorkizTeam
{
    /**
     * @return array{members: list<array<string, mixed>>, failed: ?string}
     */
    public function raw(SocialConnection $connection): array
    {
        $token = (string) ($connection->credentials['api_token'] ?? '');

        if ($token === '') {
            return ['members' => [], 'failed' => 'This branch has no stored token.'];
        }

        try {
            $response = Http::acceptJson()->get('https://api.workiz.com/api/v1/'.$token.'/team/all/');
        } catch (ConnectionException) {
            // Never the exception's message — the token is in the path.
            return ['members' => [], 'failed' => 'Workiz could not be reached.'];
        }

        if ($response->failed()) {
            return ['members' => [], 'failed' => 'Workiz refused the team list ('.$response->status().').'];
        }

        $members = [];

        foreach ($response->json('data') ?? [] as $member) {
            if (is_array($member)) {
                $members[] = $member;
            }
        }

        return ['members' => $members, 'failed' => null];
    }
}
