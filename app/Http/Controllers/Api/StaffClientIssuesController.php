<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientIssue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/v1/staff/health/client-issues — the apps' own error reports,
 * grouped into problems, newest first, for the staff console's Health.
 * Staff-only (the `staff` middleware on the group): it reads across every
 * organization, the way the rest of Health does.
 */
class StaffClientIssuesController extends Controller
{
    public function index(Request $request): array
    {
        $days = min(max((int) $request->query('days', 7), 1), 90);
        $since = now()->subDays($days);

        $groups = ClientIssue::query()
            ->where('last_seen_at', '>=', $since)
            ->groupBy('fingerprint')
            ->select([
                'fingerprint',
                DB::raw('MAX(kind) as kind'),
                DB::raw('MAX(message) as message'),
                DB::raw('SUM(`count`) as total'),
                DB::raw('COUNT(DISTINCT user_id) as people'),
                DB::raw('MIN(first_seen_at) as first_seen_at'),
                DB::raw('MAX(last_seen_at) as last_seen_at'),
            ])
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        // One recent example per problem, for its stack, versions and modules.
        $samples = ClientIssue::query()
            ->whereIn('fingerprint', $groups->pluck('fingerprint'))
            ->where('last_seen_at', '>=', $since)
            ->orderByDesc('last_seen_at')
            ->get(['fingerprint', 'detail', 'platform', 'app_version', 'module'])
            ->groupBy('fingerprint');

        $byVersion = ClientIssue::query()
            ->where('last_seen_at', '>=', now()->subDay())
            ->groupBy('app_version', 'platform')
            ->select(['app_version', 'platform', DB::raw('SUM(`count`) as total')])
            ->orderByDesc('total')
            ->get();

        return [
            'days' => $days,
            'problems' => $groups->map(function ($g) use ($samples) {
                $rows = $samples->get($g->fingerprint, collect());

                return [
                    'fingerprint' => $g->fingerprint,
                    'kind' => $g->kind,
                    'message' => $g->message,
                    'total' => (int) $g->total,
                    'people' => (int) $g->people,
                    'first_seen_at' => (string) $g->first_seen_at,
                    'last_seen_at' => (string) $g->last_seen_at,
                    'versions' => $rows->pluck('app_version')->filter()->unique()->values(),
                    'platforms' => $rows->pluck('platform')->filter()->unique()->values(),
                    'modules' => $rows->pluck('module')->filter()->unique()->values(),
                    'detail' => $rows->first()?->detail,
                ];
            })->values(),
            'last_day_by_version' => $byVersion,
        ];
    }
}
