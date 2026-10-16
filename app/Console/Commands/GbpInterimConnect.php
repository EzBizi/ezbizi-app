<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\BusinessProfileOAuth;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * A-1 Chimney's INTERIM Business Profile connections, on Rodrigo's grant
 * (Jose, 2026-10-15: "shouldn't we already be able to use the a-1chimney GBP
 * one in the meantime that rodrigo gave access to"; "It should be A-1 Chimney
 * level").
 *
 * The grant is A1 Leads' OAuth client, in RODRIGO'S Cloud project, which has
 * Business Profile quota and ours does not yet. Each connection carries that
 * client in its own encrypted credentials (GoogleToken) - never the global
 * client. Rodrigo can revoke it at any time; the rows then read broken. When
 * EzBizi's own access is approved, swapping is reconnecting these rows.
 *
 * ── Which accounts ───────────────────────────────────────────────
 *
 * Measured 2026-10-15: the grant reaches 34 accounts but 46 locations. The
 * personal account holds 43; three location groups (New York, Oklahoma City,
 * Pittsburgh) hold the other three; the other 30 groups hold only locations
 * the personal account already has. A location under two connections would
 * have its reviews imported twice, so only an account that adds a location
 * nobody earlier covers is connected.
 *
 * The three values arrive as JSON on STDIN, piped from the credentials file
 * by script, so they are never typed, printed or committed.
 */
class GbpInterimConnect extends Command
{
    protected $signature = 'ezbizi:gbp-interim-connect {company : the company these belong to (A-1 Chimney)}';

    protected $description = "Connect A-1 Chimney's Business Profile through A1 Leads' grant (credentials on STDIN)";

    /** Tests stand in for STDIN here; never set outside a test. */
    public static ?string $stdin = null;

    public function handle(TenantContext $tenant): int
    {
        $given = json_decode(self::$stdin ?? (string) stream_get_contents(STDIN), true);
        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! is_string($given[$key] ?? null) || $given[$key] === '') {
                $this->error("No {$key} on STDIN.");

                return self::FAILURE;
            }
        }

        $company = Company::query()->withoutGlobalScopes()->findOrFail($this->argument('company'));
        $token = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => $given['client_id'], 'client_secret' => $given['client_secret'],
            'refresh_token' => $given['refresh_token'], 'grant_type' => 'refresh_token',
        ])->json('access_token');
        if (! is_string($token) || $token === '') {
            $this->error('Google would not renew that grant.');

            return self::FAILURE;
        }

        $covered = [];
        $kept = [];
        foreach ($this->accounts($token) as $account) {
            $locations = $this->locations($token, (string) $account['name']);
            if (array_diff($locations, $covered) === []) {
                continue;
            }
            $covered = [...$covered, ...$locations];
            $kept[] = $account + ['count' => count($locations)];
        }

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($company->organization_id), function () use ($kept, $company, $given) {
            foreach ($kept as $account) {
                $remote = str_replace('accounts/', '', (string) $account['name']);
                $row = SocialConnection::query()
                    ->where('provider', SocialProvider::GoogleBusinessProfile->value)
                    ->where('remote_id', $remote)->first() ?? new SocialConnection;
                $row->forceFill([
                    'organization_id' => $company->organization_id,
                    'scope_type' => SocialConnection::SCOPE_COMPANY,
                    'scope_id' => $company->getKey(),
                    'provider' => SocialProvider::GoogleBusinessProfile->value,
                    'remote_id' => $remote,
                    'display_name' => mb_substr((string) ($account['accountName'] ?? $remote), 0, 191).' (A1 Leads access)',
                ]);
                $row->connect([
                    'refresh_token' => $given['refresh_token'],
                    'client_id' => $given['client_id'],
                    'client_secret' => $given['client_secret'],
                    'interim' => 'a1leads',
                ]);
                $row->save();
                $this->line("Connected {$row->display_name}: {$account['count']} location(s).");
            }
        });

        $this->info(count($kept).' connection(s), '.count(array_unique($covered)).' location(s).');

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function accounts(string $token): array
    {
        $all = [];
        $page = null;
        do {
            $body = Http::withToken($token)->get(BusinessProfileOAuth::ACCOUNTS_ENDPOINT, array_filter(['pageSize' => 20, 'pageToken' => $page]))->json();
            $all = [...$all, ...(array) ($body['accounts'] ?? [])];
            $page = $body['nextPageToken'] ?? null;
        } while ($page);

        // The personal account first: it holds most locations in one place.
        usort($all, fn ($a, $b) => (($b['type'] ?? '') === 'PERSONAL') <=> (($a['type'] ?? '') === 'PERSONAL'));

        return $all;
    }

    /** @return list<string> location names */
    private function locations(string $token, string $account): array
    {
        $names = [];
        $page = null;
        do {
            $body = Http::withToken($token)->get('https://mybusinessbusinessinformation.googleapis.com/v1/'.$account.'/locations',
                array_filter(['readMask' => 'name', 'pageSize' => 100, 'pageToken' => $page]))->json();
            $names = [...$names, ...array_column((array) ($body['locations'] ?? []), 'name')];
            $page = $body['nextPageToken'] ?? null;
        } while ($page);

        return $names;
    }
}
