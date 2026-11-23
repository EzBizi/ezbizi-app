<?php

namespace App\Services\Ads;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
 * twice asks Google once; a range that takes in today, 15 minutes (audit,
 * 2026-11-10: today's spend stood still from the first read). Cost in USD minor units; an account billed in
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
    public function forBranch(Franchise $branch, CarbonImmutable $from, CarbonImmutable $to, bool $activeOnly = false): array
    {
        return $this->sum($this->accounts((string) $branch->organization_id)->whereRaw(SocialConnection::BRANCH_SQL.' = ?', [$branch->getKey()])->get(), $from, $to, $activeOnly);
    }

    /**
     * The spend of the accounts linked to no branch, so a report can say it
     * rather than drop it (audit, 2026-11-10: $4,645.62 on Nov 9). $companies:
     * only accounts made for those companies; null, all of them.
     *
     * @param  list<string>|null  $companies
     */
    public function unmapped(string $organizationId, ?array $companies, CarbonImmutable $from, CarbonImmutable $to, bool $activeOnly = false): array
    {
        return $this->sum($this->accounts($organizationId)->whereRaw(SocialConnection::BRANCH_SQL.' IS NULL')
            ->when($companies !== null, fn ($q) => $q->where('scope_type', SocialConnection::SCOPE_COMPANY)->whereIn('scope_id', $companies))
            ->get(), $from, $to, $activeOnly);
    }

    /**
     * The spend of the accounts given, in forBranch()'s shape. The caller picks them from the
     * business's own lists (Reports' sub company sources), never from a request.
     *
     * @param  Collection<int, SocialConnection>  $accounts
     */
    public function forAccounts(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to, bool $activeOnly = false): array
    {
        return $this->sum($accounts, $from, $to, $activeOnly);
    }

    private function accounts(string $organizationId): Builder
    {
        return SocialConnection::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('provider', SocialProvider::GoogleAds->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->where('is_manager', false);
    }

    /** @param  Collection<int, SocialConnection>  $accounts */
    private function sum(Collection $accounts, CarbonImmutable $from, CarbonImmutable $to, bool $activeOnly): array
    {
        $total = ['lsa' => ['cost_minor' => 0, 'clicks' => 0, 'conversions' => 0.0]];
        $total['ppc'] = $total['lsa'];
        $total['accounts'] = 0;
        $total['refused'] = [];
        $today = $to->toDateString() >= CarbonImmutable::now($to->getTimezone())->toDateString();

        foreach ($accounts as $account) {
            try {
                $cost = Cache::remember(
                    "ads-cost:{$account->getKey()}:{$from->toDateString()}:{$to->toDateString()}".($activeOnly ? ':active' : ''),
                    // A past day still settles for hours after its midnight: an hour, not until 23:59 UTC (audit #14, 2026-11-11).
                    $today ? now()->addMinutes(15) : now()->addHour(),
                    fn () => $this->performance->channelCost($account, Carbon::parse($from), Carbon::parse($to), $activeOnly),
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
