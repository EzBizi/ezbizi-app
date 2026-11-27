<?php

namespace App\Console\Commands;

use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Google connections made before the consent asked for `email` (Jose,
 * 2026-11-02): asks Google whose each grant is. tokeninfo names the login
 * only when the grant happens to carry the email scope, so the rest stay
 * null ("unknown, reconnect to see"). One call per grant, not per row.
 */
class GoogleConnectionEmails extends Command
{
    protected $signature = 'ezbizi:google-connection-emails';

    protected $description = 'Record the Google login on Google connections that have none';

    public function handle(): int
    {
        $services = [
            SocialProvider::GoogleBusinessProfile->value => 'business_profile',
            SocialProvider::GoogleAds->value => 'google_ads',
            SocialProvider::YouTube->value => 'youtube',
        ];
        $rows = SocialConnection::withoutGlobalScopes()->whereIn('provider', array_keys($services))
            ->whereNull('account_email')->where('status', SocialConnection::STATUS_CONNECTED)->get();
        $named = 0;

        foreach ($rows->groupBy(fn (SocialConnection $c) => (string) ($c->credentials['refresh_token'] ?? '')) as $grant => $same) {
            if ($grant === '') {
                continue;
            }
            try {
                $token = GoogleToken::forConnection($same->first(), $services[$same->first()->provider]);
            } catch (\RuntimeException $e) {
                $this->warn($same->first()->display_name.': '.$e->getMessage());

                continue;
            }
            $email = Http::timeout(10)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $token])->json('email');
            if (is_string($email) && $email !== '') {
                $named += SocialConnection::withoutGlobalScopes()->whereKey($same->modelKeys())->update(['account_email' => strtolower($email)]);
            }
        }
        $this->info("{$named} of {$rows->count()} connections named; the rest need reconnecting to show their login.");

        return self::SUCCESS;
    }
}
