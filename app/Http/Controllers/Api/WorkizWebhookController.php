<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RereadWorkizRecord;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Workiz\WorkizV2;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Workiz saying a job, lead, invoice or estimate changed: a rule in the
 * branch's Workiz Automation Center whose action is "post webhook" to
 * /api/v1/social/workiz/webhook/{connection}.
 *
 * A NUDGE, NOT A DELIVERY. Workiz can sign a webhook only with an auth key
 * typed into the rule, so the body proves nothing about its origin. All it is
 * trusted for is WHICH record to read: RereadWorkizRecord reads that record
 * from the branch's own account with the branch's own key and lands what
 * Workiz answers. A forged call can at worst make EzBizi read a record again,
 * and at most 120 a minute per branch.
 */
final class WorkizWebhookController
{
    /** A v2 id names its kind: JOB-…, IV-…, ES-… */
    private const KINDS = ['JOB' => 'job', 'IV' => 'invoice', 'ES' => 'estimate'];

    public function __invoke(Request $request, string $connection): Response
    {
        $branch = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)->whereKey($connection)->first();

        if ($branch === null || ! WorkizV2::uses($branch)) {
            return response('', 404);
        }

        $id = (string) $request->input('data.id', '');
        $kind = str_starts_with((string) $request->input('trigger.type', ''), 'lead') ? 'lead'
            : (preg_match('/^([A-Z]{2,3})-[A-Za-z0-9]{4,40}$/', $id, $m) ? (self::KINDS[$m[1]] ?? null) : null);

        if ($kind === null) {
            return response('', 422);
        }

        Cache::forever(\App\Support\Workiz\WorkizHealth::NUDGED.$branch->getKey(), now()->toIso8601String());

        // One read per record per 20 seconds: a save that fires three rules
        // is one change. Every lead nudge for a branch is one import.
        $once = 'workiz-webhook:'.$branch->getKey().':'.($kind === 'lead' ? 'leads' : $id);

        if (RateLimiter::attempt('workiz-webhook:'.$branch->getKey(), 120, fn () => true) && Cache::add($once, true, 20)) {
            RereadWorkizRecord::dispatch($branch->getKey(), $kind, $id);
        }

        return response('', 202);
    }
}
