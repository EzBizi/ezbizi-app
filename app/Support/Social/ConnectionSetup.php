<?php

namespace App\Support\Social;

use App\Jobs\OnboardWhatsAppNumber;
use App\Jobs\SubscribeMetaMessages;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The setup a connect queues after the token is stored (Jose, 2026-11-12:
 * "doing a connection should take care of the whole process").
 *
 *  - failed(): a setup job that gave up marks its card broken with what did
 *    not finish, instead of leaving it green with the error in failed_jobs.
 *  - readBack(): on a live token, ask whether the setup is still in place and
 *    queue the setup job again when it is not. A missing setup never marks the
 *    row broken by itself; only that job failing again does (via failed()).
 *    Thumbtack's webhook is read back by ConnectionLiveness::thumbtackHeard().
 */
final class ConnectionSetup
{
    public static function failed(string $connectionId, string $sentence, Throwable $e): void
    {
        $c = SocialConnection::query()->withoutGlobalScopes()->find($connectionId);
        if ($c === null || $c->status !== SocialConnection::STATUS_CONNECTED) {
            return;
        }

        Log::warning('Connection setup failed.', ['connection_id' => $connectionId, 'reason' => $e->getMessage()]);
        // A failed job has no tenant, and saving a connection needs one.
        app(TenantContext::class)->run(
            Organization::query()->withoutGlobalScopes()->findOrFail($c->organization_id),
            function () use ($c, $sentence) {
                $c->forceFill(['status' => SocialConnection::STATUS_BROKEN, 'status_reason' => $sentence])->save();
                ConnectionChecks::record($c, 'setup', ConnectionChecks::FAIL, $sentence);
            },
        );
    }

    /** True when something was missing and its setup was queued again. */
    public static function readBack(SocialConnection $c): bool
    {
        try {
            return match ($c->provider) {
                SocialProvider::FacebookPage->value => self::facebookPage($c),
                SocialProvider::WhatsApp->value => self::whatsApp($c),
                default => false,
            };
        } catch (Throwable $e) {
            report($e); // A read-back that cannot read says nothing about the setup.

            return false;
        }
    }

    private static function facebookPage(SocialConnection $c): bool
    {
        $token = ((array) $c->credentials)['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        $r = Http::timeout(15)->get('https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/'.$c->remote_id.'/subscribed_apps',
            ['access_token' => $token]);
        if (! $r->successful()) {
            return false;
        }

        $appId = (string) config('services.meta.client_id');
        foreach ((array) $r->json('data') as $app) {
            $ours = $appId === '' || (string) ($app['id'] ?? '') === $appId;
            if ($ours && in_array('messages', (array) ($app['subscribed_fields'] ?? []), true)) {
                ConnectionChecks::record($c, 'messages', ConnectionChecks::PASS);

                return false;
            }
        }

        SubscribeMetaMessages::dispatch((string) $c->getKey());
        ConnectionChecks::record($c, 'messages', ConnectionChecks::UNKNOWN, 'Was missing; set up again.');

        return true;
    }

    private static function whatsApp(SocialConnection $c): bool
    {
        $routed = WhatsAppNumber::query()->withoutGlobalScopes()
            ->where('phone_number_id', (string) $c->remote_id)
            ->where('organization_id', $c->organization_id)->exists();
        $waba = ((array) $c->credentials)['waba_id'] ?? null;
        if ($routed) {
            ConnectionChecks::record($c, 'routing', ConnectionChecks::PASS);

            return false;
        }
        if (! is_string($waba) || $waba === '') {
            return false;
        }

        OnboardWhatsAppNumber::dispatch((string) $c->getKey(), $waba);
        ConnectionChecks::record($c, 'routing', ConnectionChecks::UNKNOWN, 'Was missing; set up again.');

        return true;
    }
}
