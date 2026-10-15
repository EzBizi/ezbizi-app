<?php

namespace App\Http\Controllers\Api;

use App\Models\ClientIssue;
use App\Support\Monitoring\ClientIssueScrubber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/v1/client-issues — the apps reporting their own errors, crashes,
 * freezes and failed calls (Jose, 2026-10-15). Works signed out too: a crash
 * on the sign-in screen is still a crash. The person is read from the bearer
 * token when there is one; the platform and version from the headers every
 * request already carries.
 *
 * Never fails the caller: an error report that errors is the last thing an
 * app in trouble needs. Over the rate limit, reports are dropped and 204 is
 * still returned.
 */
class ClientIssuesController extends Controller
{
    /** Per person (or per address when signed out), per hour. */
    private const PER_HOUR = 120;

    /** Repeats of one problem inside this window fold into one row. */
    private const FOLD_MINUTES = 10;

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'issues' => ['required', 'array', 'max:20'],
            'issues.*.kind' => ['required', 'string', 'in:'.implode(',', ClientIssue::KINDS)],
            'issues.*.message' => ['required', 'string', 'max:4000'],
            'issues.*.detail' => ['nullable', 'string', 'max:40000'],
            'issues.*.module' => ['nullable', 'string', 'max:64'],
            'issues.*.count' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $user = auth('sanctum')->user();
        $who = $user ? 'user:'.$user->getKey() : 'ip:'.$request->ip();
        $platform = mb_substr((string) $request->header('X-EzBizi-Client-Platform'), 0, 16) ?: null;
        $version = mb_substr((string) $request->header('X-EzBizi-Client-Version'), 0, 32) ?: null;

        foreach ($data['issues'] as $issue) {
            if (RateLimiter::tooManyAttempts('client-issues:'.$who, self::PER_HOUR)) {
                break;
            }
            RateLimiter::hit('client-issues:'.$who, 3600);

            $message = ClientIssueScrubber::scrub($issue['message'], 500) ?? '(no message)';
            $detail = ClientIssueScrubber::scrub($issue['detail'] ?? null, 8000);
            $fingerprint = ClientIssueScrubber::fingerprint($issue['kind'], $message, $detail);
            $count = $issue['count'] ?? 1;

            $existing = ClientIssue::query()
                ->where('fingerprint', $fingerprint)
                ->where('app_version', $version)
                ->where('user_id', $user?->getKey())
                ->where('last_seen_at', '>=', now()->subMinutes(self::FOLD_MINUTES))
                ->first();

            if ($existing !== null) {
                $existing->increment('count', $count, ['last_seen_at' => now()]);

                continue;
            }

            ClientIssue::create([
                'organization_id' => $user?->organization_id,
                'user_id' => $user?->getKey(),
                'kind' => $issue['kind'],
                'fingerprint' => $fingerprint,
                'message' => $message,
                'detail' => $detail,
                'platform' => $platform,
                'app_version' => $version,
                'module' => $issue['module'] ?? null,
                'count' => $count,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        return response()->noContent();
    }
}
