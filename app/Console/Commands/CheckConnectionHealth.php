<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\ConnectionChecks;
use App\Support\Social\Liveness\ConnectionLiveness;
use App\Support\Social\Liveness\Liveness;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Daily: does every connected token still work? (Jose, 2026-11-03.)
 *
 * A Settings › Integrations card must never say "Connected" on a dead token.
 * Each connected row gets one cheap read-only call (ConnectionLiveness):
 *  - it works: last_reached_at and last_checked_at are stamped;
 *  - the provider refuses the grant (401, invalid_grant, revoked): the row
 *    is marked broken with a sentence, and last_checked_at is stamped;
 *  - no answer, a 5xx or a 429: nothing changes, so last_checked_at keeps
 *    saying when a verdict was last had.
 *
 * Calls to one provider are spaced (--pause) so a tenant with forty Google
 * rows does not burst the token endpoint.
 */
final class CheckConnectionHealth extends Command
{
    protected $signature = 'ezbizi:check-connection-health {--pause=750 : Milliseconds between two calls to one provider}';

    protected $description = 'Check every connected integration token still works; mark refused ones broken';

    public function handle(TenantContext $tenant, ConnectionLiveness $liveness): int
    {
        $pause = max(0, (int) $this->option('pause'));
        $counts = [Liveness::ALIVE => 0, Liveness::REFUSED => 0, Liveness::UNKNOWN => 0];
        $organizations = [];

        $rows = SocialConnection::withoutGlobalScopes()
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->orderBy('provider')->orderBy('organization_id')
            ->get()
            ->filter(fn (SocialConnection $c) => ConnectionLiveness::checks((string) $c->provider))
            ->groupBy('provider');

        foreach ($rows as $provider => $connections) {
            // One Thumbtack login's grant is shared by all its business rows,
            // and every exchange rotates it: one per organization, fanned out.
            $thumbtackSeen = [];

            foreach ($connections->values() as $i => $c) {
                if ($i > 0 && $pause > 0) {
                    Sleep::for($pause)->milliseconds();
                }

                $organization = $organizations[$c->organization_id]
                    ??= Organization::withoutGlobalScopes()->find($c->organization_id);
                if ($organization === null) {
                    continue;
                }

                $result = $tenant->run($organization, function () use ($c, $liveness, $provider, &$thumbtackSeen) {
                    if ($provider === SocialProvider::Thumbtack->value && isset($thumbtackSeen[$c->organization_id])) {
                        $c->refresh();
                        // The shared renewal answers for it; its own webhook is read back here.
                        $result = $c->status === SocialConnection::STATUS_BROKEN
                            ? Liveness::refused((string) $c->status_reason)
                            : ConnectionLiveness::thumbtackHeard($c, $thumbtackSeen[$c->organization_id]);
                    } else {
                        $result = $liveness->check($c);
                        $thumbtackSeen[$c->organization_id] = $result;
                    }

                    self::record($c, $result);

                    return $result;
                });

                $counts[$result->verdict]++;
            }
        }

        $this->info("{$counts[Liveness::ALIVE]} alive, {$counts[Liveness::REFUSED]} refused, {$counts[Liveness::UNKNOWN]} no verdict");

        return self::SUCCESS;
    }

    /** Also used by POST integrations/{id}/recheck and CheckNewConnections. */
    public static function record(SocialConnection $c, Liveness $result): void
    {
        $c->refresh();   // a renewal may have just rotated its credentials

        match ($result->verdict) {
            Liveness::ALIVE => $c->forceFill(['last_reached_at' => now(), 'last_checked_at' => now()])->save(),
            Liveness::REFUSED => $c->forceFill([
                'status' => SocialConnection::STATUS_BROKEN,
                'status_reason' => $result->reason,
                'last_checked_at' => now(),
            ])->save(),
            default => null,
        };

        // Per check, for GET integrations/overview.
        ConnectionChecks::record($c, 'sign_in', match ($result->verdict) {
            Liveness::ALIVE => ConnectionChecks::PASS,
            Liveness::REFUSED => ConnectionChecks::FAIL,
            default => ConnectionChecks::UNKNOWN,
        }, $result->reason);
    }
}
