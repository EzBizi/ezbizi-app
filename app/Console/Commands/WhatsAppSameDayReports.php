<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\WhatsAppGroup;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\SameDayReport;
use App\Support\Social\WhatsAppGroups;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The nightly Same-Day Reports to WhatsApp, replacing the Rodrigo bot's
 * (docs/REPLACING-RODRIGO-BOT.md): each branch's to its `daily:<franchise>`
 * group, then the all-companies summary to `financial`.
 *
 * Once per group per day: a re-run (or a second scheduler) finds the cache
 * key and sends nothing, so nobody gets the same report twice.
 */
class WhatsAppSameDayReports extends Command
{
    protected $signature = 'ezbizi:whatsapp-same-day-reports {--date= : Y-m-d, default today in New York}';

    protected $description = 'Send each branch its Same-Day Report on WhatsApp, then the summary';

    public function handle(TenantContext $tenant, WhatsAppGroups $groups): int
    {
        $date = (string) ($this->option('date') ?: Carbon::now(\App\Support\Settings\OrgTimezone::of())->toDateString());
        $sent = 0;

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', WhatsAppGroup::query()->withoutGlobalScopes()->select('organization_id'))
            ->get();

        foreach ($organizations as $organization) {
            $tenant->run($organization, function () use ($organization, $groups, $date, &$sent) {
                $daily = WhatsAppGroup::query()->where('purpose', 'like', 'daily:%')->get();

                foreach ($daily as $group) {
                    $branch = Franchise::query()->find(substr($group->purpose, strlen('daily:')));

                    if ($branch !== null) {
                        $sent += $this->once($group->getKey(), $date, fn () => $groups->deliver(
                            $group->purpose,
                            BranchName::of($branch).' Same-Day Report for '.Carbon::parse($date)->format('M j'),
                            SameDayReport::forFranchise($branch, $date),
                        ));
                    }
                }

                $financial = WhatsAppGroup::query()->where('purpose', 'financial')->first();

                if ($financial !== null) {
                    $sent += $this->once($financial->getKey(), $date, fn () => $groups->deliver(
                        'financial',
                        'Same-Day Company Summary for '.Carbon::parse($date)->format('M j'),
                        SameDayReport::summary($organization, $date),
                    ));
                }
            });
        }

        $this->info("Sent {$sent} Same-Day report deliveries for {$date}.");

        return self::SUCCESS;
    }

    private function once(string $groupId, string $date, callable $send): int
    {
        $key = "whatsapp-same-day:{$groupId}:{$date}";

        if (! Cache::add($key, true, now()->addDays(2))) {
            return 0;
        }

        try {
            return $send();
        } catch (Throwable $e) {
            // Free the key so the next run tries again, and keep the reason.
            Cache::forget($key);
            report($e);
            $this->error($e->getMessage());

            return 0;
        }
    }
}
