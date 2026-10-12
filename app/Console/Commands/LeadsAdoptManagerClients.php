<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Connect every enabled client account under an already-connected manager.
 *
 * A connect made before 2026-09-09 stored only the accounts the signed-in user
 * reaches directly. The same grant reaches every client of any manager among
 * them, with `login-customer-id` set to that manager; nothing about the grant
 * has to change, only which rows exist. This walks each connected Google Ads
 * account, asks Google whether it is a manager, and for each enabled client
 * not yet connected creates the row under the same organization, scope and
 * credentials, reading through the manager. Idempotent: a client already
 * connected, directly or through any manager, is left alone.
 *
 * New connects do this at identify() time; this is for the rows that predate
 * that.
 */
class LeadsAdoptManagerClients extends Command
{
    protected $signature = 'leads:adopt-manager-clients {--dry-run : Say what would be created and create nothing}';

    protected $description = 'Connect every enabled client under each connected Google Ads manager, reading through it';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $created = 0;
        $skipped = 0;
        $dry     = (bool) $this->option('dry-run');

        foreach (Organization::withoutGlobalScopes()->cursor() as $organization) {
            $this->tenant->run($organization, function () use (&$created, &$skipped, $dry) {
                $rows = SocialConnection::query()
                    ->where('provider', SocialProvider::GoogleAds->value)
                    ->where('status', SocialConnection::STATUS_CONNECTED)
                    ->get();

                $known = $rows->map(fn ($r) => preg_replace('/\D/', '', (string) $r->remote_id))->all();

                foreach ($rows as $manager) {
                    $managerId = preg_replace('/\D/', '', (string) $manager->remote_id) ?? '';

                    try {
                        $token = GoogleToken::forConnection($manager, 'google_ads');
                    } catch (Throwable $e) {
                        $this->warn("{$manager->remote_id}: {$e->getMessage()}");

                        continue;
                    }

                    // Google's word on which rows are managers, kept on the row
                    // for the Ads report (Jose, 2026-10-12).
                    $isManager = GoogleAdsOAuth::describe($token, $managerId, $manager->login_customer_id)['manager'];
                    // Only ever raised: a failed read says false, and an account does not
                    // stop being a manager.
                    if ($isManager && ! $manager->is_manager && ! $dry) {
                        $manager->forceFill(['is_manager' => true])->save();
                    }

                    if (! $isManager) {
                        continue;
                    }

                    foreach (GoogleAdsOAuth::clientsOf($token, $managerId) as $client) {
                        if (in_array($client->remoteId, $known, true)) {
                            $skipped++;

                            continue;
                        }

                        $this->line(($dry ? 'would connect ' : 'connecting ')."{$client->remoteId} {$client->displayName} via {$managerId}");
                        $known[] = $client->remoteId;
                        $created++;

                        if ($dry) {
                            continue;
                        }

                        $row = new SocialConnection;
                        $row->forceFill([
                            'organization_id'   => $manager->organization_id,
                            'scope_type'        => $manager->scope_type,
                            'scope_id'          => $manager->scope_id,
                            'provider'          => SocialProvider::GoogleAds->value,
                            'remote_id'         => $client->remoteId,
                            'login_customer_id' => $managerId,
                            'display_name'      => $client->displayName,
                        ]);
                        $row->connect((array) $manager->credentials);
                        $row->save();

                        $this->audit->log('social.connected', $row, [
                            'provider'     => $row->provider,
                            'display_name' => $row->display_name,
                            'scope_type'   => $row->scope_type,
                            'via_manager'  => $managerId,
                        ]);
                    }
                }
            });
        }

        $this->info(($dry ? 'Would connect ' : 'Connected ')."{$created}, already connected {$skipped}.");

        return self::SUCCESS;
    }
}
