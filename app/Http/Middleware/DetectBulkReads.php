<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\ApiKey;
use App\Support\Notifications\NotificationDispatcher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Counts the DISTINCT customer, lead and job records one actor reads inside a
 * window, and the distinct list pages it asks for; past the threshold for its
 * role (config/bulk_read.php) it writes one `security.bulk_read` audit row and
 * tells the organization's administrators — once per actor per window.
 *
 * The actor is the API key when the request carries one (an MCP agent is a
 * key), otherwise the person. Never refuses anything: the throttles do that.
 */
class DetectBulkReads
{
    private const SHOW = '#^api/v1/(customers|leads|jobs)/([0-9a-fA-F-]{36}|\d+)(/|$)#';

    private const LIST = '#^api/v1/(customers|leads|jobs)$#';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful() && $request->user() instanceof User) {
            try {
                $this->count($request);
            } catch (Throwable $e) {
                // A broken counter must never break the read it was watching.
                report($e);
            }
        }

        return $response;
    }

    private function count(Request $request): void
    {
        $path = $request->path();

        if (preg_match(self::SHOW, $path, $m)) {
            $kind = 'records';
            $item = $m[1].':'.$m[2];
        } elseif (preg_match(self::LIST, $path, $m)) {
            $kind = 'pages';
            $item = $m[1].'?'.($request->getQueryString() ?? '');
        } else {
            return;
        }

        $user = $request->user();
        $token = $user->currentAccessToken();
        $isKey = ApiKey::is($token);
        $actor = $isKey ? 'key:'.$token->getKey() : 'user:'.$user->getKey();

        $minutes = max(1, (int) config('bulk_read.window_minutes', 10));
        $window = intdiv(now()->getTimestamp(), $minutes * 60);
        $ttl = now()->addMinutes($minutes * 2);

        $key = "bulk_read:{$actor}:{$window}:{$kind}";
        $seen = Cache::get($key, []);
        $seen[$item] = true;
        Cache::put($key, $seen, $ttl);

        $roleKey = $isKey ? 'api_key' : Str::snake(Str::lower((string) ($user->role?->name ?? 'default')));
        $limits = config("bulk_read.thresholds.{$roleKey}") ?? config('bulk_read.thresholds.default');
        $limit = (int) ($limits[$kind] ?? PHP_INT_MAX);

        if (count($seen) <= $limit) {
            return;
        }

        // add() is atomic: only the first request over the line raises it.
        if (! Cache::add("bulk_read:{$actor}:{$window}:alerted", true, $ttl)) {
            return;
        }

        $this->raise($user, $isKey ? $token : null, $kind, count($seen), $limit, $roleKey, $minutes, array_slice(array_keys($seen), -20));
    }

    private function raise(User $user, mixed $token, string $kind, int $count, int $limit, string $roleKey, int $minutes, array $sample): void
    {
        $what = $kind === 'records' ? 'customer, lead or job records' : 'pages of the customer, lead or job lists';
        $who = $token !== null ? "API key \"{$token->name}\" of {$user->name}" : $user->name;

        $this->audit->log('security.bulk_read', $token ?? $user, metadata: [
            'actor' => $token !== null ? 'api_key' : 'user',
            'user_id' => $user->getKey(),
            'token_id' => $token?->getKey(),
            'kind' => $kind,
            'count' => $count,
            'threshold' => $limit,
            'threshold_role' => $roleKey,
            'window_minutes' => $minutes,
            'recent' => $sample,
        ], actor: $user);

        $admins = User::query()
            ->where('organization_id', $user->organization_id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->where('is_administrator', true))
            ->get();

        foreach ($admins as $admin) {
            $this->notifications->dispatch(
                title: 'Unusual number of records read',
                body: "{$who} read {$count} {$what} in {$minutes} minutes (alert at {$limit}).",
                onlyTo: $admin,
                source: 'system',
                inAppOnly: true,
            );
        }

        Log::warning('security.bulk_read', ['actor' => $token !== null ? 'api_key' : 'user', 'user_id' => $user->getKey(), 'kind' => $kind, 'count' => $count]);
    }
}
