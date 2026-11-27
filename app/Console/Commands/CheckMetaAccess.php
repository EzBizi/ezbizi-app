<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Can each Meta connection still reach its account? (Jose, 2026-11-02.)
 *
 * A-1's WhatsApp token stayed valid while the business took EzBizi's
 * permissions away (its scopes fell to `public_profile`). Nothing noticed:
 * the card said "connected" and four days of messages never arrived. This asks
 * Meta once a day with each connection's own token. A refusal (an OAuth or
 * permission error) marks it broken, which turns its Integrations card red,
 * and tells whoever can connect it. A timeout or a 5xx says nothing about
 * access, so it changes nothing. A connection that answers again is marked
 * connected.
 */
final class CheckMetaAccess extends Command
{
    protected $signature = 'ezbizi:meta-access-check';

    protected $description = 'Mark Meta and WhatsApp connections whose account Meta no longer lets EzBizi reach';

    public const REASON = 'Meta no longer lets EzBizi reach this account. Reconnect it.';

    private const TIMEOUT = 15;

    public function handle(TenantContext $tenant, NotificationDispatcher $notify): int
    {
        $providers = [
            SocialProvider::FacebookPage->value, SocialProvider::InstagramProfessional->value,
            SocialProvider::FacebookAds->value, SocialProvider::WhatsApp->value,
        ];

        $broken = 0;
        foreach (SocialConnection::withoutGlobalScopes()->whereIn('provider', $providers)
            ->whereIn('status', [SocialConnection::STATUS_CONNECTED, SocialConnection::STATUS_BROKEN])->get() as $c) {
            $reached = $this->reaches($c);
            if ($reached === null) {
                continue;   // Meta did not answer: no verdict
            }

            $tenant->run(Organization::findOrFail($c->organization_id), function () use ($c, $reached, $notify, &$broken) {
                if ($reached) {
                    if ($c->status === SocialConnection::STATUS_BROKEN && $c->status_reason === self::REASON) {
                        $c->forceFill(['status' => SocialConnection::STATUS_CONNECTED, 'status_reason' => null, 'last_reached_at' => now()])->save();
                    }

                    return;
                }
                if ($c->status === SocialConnection::STATUS_BROKEN) {
                    return;   // already said once
                }
                $c->forceFill(['status' => SocialConnection::STATUS_BROKEN, 'status_reason' => self::REASON])->save();
                $broken++;

                $permission = SocialProvider::tryFrom($c->provider)?->connectPermission() ?? 'marketing.edit';
                $who = User::query()->where('is_active', true)->get()
                    ->filter(fn (User $u) => $u->hasPermission($permission))->modelKeys();
                if ($who !== []) {
                    $notify->dispatch(
                        title: ($c->display_name ?: SocialProvider::tryFrom($c->provider)?->label() ?? 'A Meta account').' needs reconnecting',
                        body: self::REASON.' Settings › Integrations.',
                        subject: $c,
                        userIds: $who,
                    );
                }
            });
        }

        $this->info("$broken newly broken");

        return self::SUCCESS;
    }

    /** True reached, false refused, null when Meta did not answer. */
    private function reaches(SocialConnection $c): ?bool
    {
        $token = $c->credentials['access_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        [$url, $id] = match ($c->provider) {
            SocialProvider::InstagramProfessional->value => ['https://graph.instagram.com/v21.0/', 'me'],
            SocialProvider::FacebookAds->value => ['https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/', 'me'],
            // A Page by its id; a WhatsApp number by its phone number id.
            default => ['https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION.'/', (string) $c->remote_id],
        };

        try {
            $r = Http::timeout(self::TIMEOUT)->get($url.$id, ['fields' => 'id', 'access_token' => $token]);
        } catch (ConnectionException) {
            return null;
        }

        if ($r->successful()) {
            return true;
        }

        return $r->serverError() ? null : false;
    }
}
