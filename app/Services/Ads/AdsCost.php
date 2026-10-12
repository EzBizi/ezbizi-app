<?php

namespace App\Services\Ads;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Google ad cost for one branch, for Reports' ad cost vs Workiz jobs
 * (2026-10-12). "LS Ads" is LOCAL_SERVICES campaigns, "PPC" is every other
 * campaign type. A branch's accounts are the Google Ads connections whose
 * leads_franchise_id names it (Jose approved the mapping 2026-10-12);
 * managers hold no spend and are left out.
 *
 * Cached per account and range until the end of the day, so a report opened
 * twice asks Google once. Cost in USD minor units; an account billed in
 * anything else is listed in `refused` rather than summed into a wrong total.
 */
final class AdsCost
{
    public function __construct(private readonly CampaignPerformance $performance) {}

    /**
     * @return array{
     *     lsa: array{cost_minor: int, clicks: int, conversions: float},
     *     ppc: array{cost_minor: int, clicks: int, conversions: float},
     *     accounts: int,
     *     refused: list<array{account: string, because: string}>,
     * }
     */
    public function forBranch(Franchise $branch, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $total = ['lsa' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0]];
        $total['ppc'] = $total['lsa'];
        $total['accounts'] = 0;
        $total['refused'] = [];

        $accounts = SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $branch->organization_id)
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->where('leads_franchise_id', $branch->getKey())
            ->where('is_manager', false)
            ->get();

        foreach ($accounts as $account) {
            try {
                $cost = Cache::remember(
                    "ads-cost:{$account->getKey()}:{$from->toDateString()}:{$to->toDateString()}",
                    now()->endOfDay(),
                    fn () => $this->performance->channelCost($account, Carbon::parse($from), Carbon::parse($to)),
                );
            } catch (RuntimeException $e) {
                $total['refused'][] = ['account' => (string) $account->display_name, 'because' => $e->getMessage()];

                continue;
            }

            if (($cost['currency'] ?? 'USD') !== 'USD') {
                $total['refused'][] = ['account' => (string) $account->display_name, 'because' => 'billed in '.$cost['currency']];

                continue;
            }

            $total['accounts']++;
            foreach (['lsa', 'ppc'] as $k) {
                foreach (['cost_minor', 'clicks', 'conversions'] as $f) {
                    $total[$k][$f] += $cost[$k][$f];
                }
            }
        }

        return $total;
    }
}
