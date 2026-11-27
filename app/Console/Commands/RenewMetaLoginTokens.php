<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\InstagramOAuth;
use App\Support\Social\MetaLoginOAuth;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThreadsOAuth;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Instagram and Threads (Meta's own logins) hand out 60-day tokens with no
 * refresh token. Unrenewed, every post, comment and DM on the account stops
 * on day 60 and the account has to be reconnected by hand. A token renews
 * only while it still works, so this runs daily and renews any with fewer
 * than RENEW_WITHIN_DAYS left.
 *
 * One consent writes one row for these logins, and the old token stays valid
 * until its own expiry, so no fan-out to sibling rows is needed (contrast
 * SocialConnection::connect()'s note on rotating refresh tokens).
 */
class RenewMetaLoginTokens extends Command
{
    public const RENEW_WITHIN_DAYS = 15;

    protected $signature = 'ezbizi:renew-meta-login-tokens';

    protected $description = 'Renew Instagram and Threads long-lived tokens before they expire';

    public function handle(TenantContext $tenant): int
    {
        Organization::query()->each(fn (Organization $org) => $tenant->run($org, function () use ($org) {
            SocialConnection::query()
                ->whereIn('provider', [SocialProvider::InstagramProfessional->value, SocialProvider::Threads->value])
                ->where('status', SocialConnection::STATUS_CONNECTED)
                ->get()
                ->each(fn (SocialConnection $c) => $this->renew($org, $c));
        }));

        return self::SUCCESS;
    }

    private function renew(Organization $org, SocialConnection $connection): void
    {
        $credentials = (array) ($connection->getAttribute('credentials') ?? []);
        $token = $credentials['access_token'] ?? null;
        $expires = $credentials['expires_at'] ?? null;

        // No expiry recorded means nothing to go on; a token is renewed only when it is due.
        if (! is_string($token) || $token === '' || ! is_string($expires)
            || Carbon::parse($expires)->isAfter(now()->addDays(self::RENEW_WITHIN_DAYS))) {
            return;
        }

        $oauth = $this->oauthFor($connection);

        try {
            $grant = $oauth->renew($token);
        } catch (SocialOAuthRefused $e) {
            // Left as it is: the publisher's own sentence names the dead token if it does die.
            $this->warn("{$org->name}: {$connection->display_name} ({$connection->provider}) was not renewed: {$e->getMessage()}");

            return;
        }

        $credentials['access_token'] = $grant->accessToken;
        $credentials['expires_at'] = now()->addSeconds($grant->expiresIn ?? 60 * 86400)->toIso8601String();
        $connection->setAttribute('credentials', $credentials);
        $connection->save();

        $this->line("{$org->name}: {$connection->display_name} ({$connection->provider}) renewed until {$credentials['expires_at']}");
    }

    private function oauthFor(SocialConnection $connection): MetaLoginOAuth
    {
        return $connection->providerEnum() === SocialProvider::Threads
            ? app(ThreadsOAuth::class)
            : app(InstagramOAuth::class);
    }
}
