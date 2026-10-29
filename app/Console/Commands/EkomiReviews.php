<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Reviews\EkomiReviews as Ekomi;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * eKomi reviews (A1 Leads takeover #43).
 *
 *   ezbizi:ekomi-reviews connect <company>   reads {"shop_id","password"} as JSON on STDIN, checks them, stores them encrypted
 *   ezbizi:ekomi-reviews sync                every eKomi connection, every organization
 */
class EkomiReviews extends Command
{
    protected $signature = 'ezbizi:ekomi-reviews {action : connect|sync} {company? : the company id, for connect}';

    protected $description = 'Connect a company to eKomi, or read every eKomi connection\'s reviews into Reviews';

    public function handle(TenantContext $tenant, Ekomi $ekomi): int
    {
        if ($this->argument('action') === 'connect') {
            $company = Company::query()->withoutGlobalScopes()->findOrFail($this->argument('company'));
            $in = json_decode((string) stream_get_contents(STDIN), true);
            $shop = trim((string) ($in['shop_id'] ?? ''));
            $password = trim((string) ($in['password'] ?? ''));
            if ($shop === '' || $password === '') {
                $this->error('Send {"shop_id": "...", "password": "..."} on STDIN.');

                return self::FAILURE;
            }
            $count = count(Ekomi::fetch($shop, $password));

            $tenant->run(Organization::query()->findOrFail($company->organization_id), function () use ($company, $shop, $password) {
                $connection = SocialConnection::query()->where('provider', SocialProvider::Ekomi->value)
                    ->where('remote_id', $shop)->first() ?? new SocialConnection;
                $connection->forceFill(['organization_id' => $company->organization_id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                    'scope_id' => $company->id, 'provider' => SocialProvider::Ekomi->value, 'remote_id' => $shop,
                    'display_name' => 'eKomi · '.$company->name, 'handle' => $shop]);
                $connection->connect(['refresh_token' => $password, 'shop_id' => $shop, 'password' => $password]);
                $connection->save();
            });
            $this->info("Connected. eKomi has {$count} reviews for this shop.");

            return self::SUCCESS;
        }

        $failed = 0;
        SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Ekomi->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get()
            ->each(function (SocialConnection $c) use ($tenant, $ekomi, &$failed) {
                try {
                    $n = $tenant->run(Organization::query()->findOrFail($c->organization_id), fn () => $ekomi->sync($c));
                    $this->line("{$c->display_name}: {$n} reviews");
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                    $this->error("{$c->display_name}: eKomi did not answer.");
                }
            });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
