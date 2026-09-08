<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Names every Google Ads connection the way Google names it.
 *
 * Connections made before identify() asked Google for a name were stored under
 * their digits — "405-172-9356" — and A-1 Chimney has twenty-two of those. This
 * asks once per unnamed row and stores what comes back. Rows a person has
 * already named (`label`) are untouched: `label` overrides `display_name` on
 * screen and is theirs, not Google's.
 *
 * Idempotent: a row that already carries a name that is not just its digits is
 * skipped, so running it again costs nothing against quota.
 */
class LeadsNameAccounts extends Command
{
    protected $signature = 'leads:name-accounts {--all : Re-ask Google even for rows that already have a name}';

    protected $description = 'Store each Google Ads connection\'s descriptive name from Google';

    public function __construct(private readonly TenantContext $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $named = 0;
        $skipped = 0;
        $failed = 0;

        foreach (Organization::withoutGlobalScopes()->cursor() as $organization) {
            $this->tenant->run($organization, function () use (&$named, &$skipped, &$failed) {
                $rows = SocialConnection::query()
                    ->where('provider', SocialProvider::GoogleAds->value)
                    ->where('status', SocialConnection::STATUS_CONNECTED)
                    ->get();

                foreach ($rows as $row) {
                    $digits = preg_replace('/\D/', '', (string) $row->remote_id) ?? '';

                    // Already named by Google (anything that is not its own
                    // digits), and not asked to redo it: leave it.
                    if (! $this->option('all')
                        && $row->display_name !== null
                        && $row->display_name !== ''
                        && $row->display_name !== GoogleAdsOAuth::readableId($digits)
                        && $row->display_name !== $digits) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $token = GoogleToken::forConnection($row, 'google_ads');
                        $name  = GoogleAdsOAuth::nameOf($token, $digits);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("{$row->remote_id}: {$e->getMessage()}");

                        continue;
                    }

                    if ($name !== $row->display_name) {
                        $row->forceFill(['display_name' => $name])->save();
                        $named++;
                        $this->line("{$row->remote_id} → {$name}");
                    } else {
                        $skipped++;
                    }
                }
            });
        }

        $this->info("Named {$named}, skipped {$skipped}, failed {$failed}.");

        return self::SUCCESS;
    }
}
