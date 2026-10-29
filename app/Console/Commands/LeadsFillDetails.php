<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Organization;
use App\Models\WebChat;
use App\Support\Leads\LeadReferral;
use App\Support\Phone\NumberNames;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Design 159's backfill (Jose, 2026-10-29: "fill names where we only show a
 * number"). Only fills what is empty: a name from the customer or earlier lead
 * that owns the phone, and a website chat's ad/campaign from the page it
 * started on. Thumbtack's details come from `ezbizi:thumbtack-lead-costs --all`.
 */
class LeadsFillDetails extends Command
{
    protected $signature = 'ezbizi:leads-fill-details {--dry-run : count, write nothing}';

    protected $description = 'Fill empty lead names and website referrals from what EzBizi already holds';

    public function handle(TenantContext $tenant, NumberNames $names): int
    {
        foreach (Organization::withoutGlobalScopes()->pluck('id') as $orgId) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($names, $orgId) {
                $named = 0;
                Lead::query()->where(fn ($q) => $q->whereNull('contact_name')->orWhere('contact_name', ''))->whereNotNull('phone')
                    ->chunkById(500, function ($leads) use ($names, &$named) {
                        $known = $names->for($leads->pluck('phone'));
                        foreach ($leads as $lead) {
                            $who = $known[$lead->phone] ?? null;
                            // A colleague's number is ours, not the customer's name.
                            if ($who === null || $who['kind'] === NumberNames::COLLEAGUE || ($who['kind'] === NumberNames::LEAD && $who['id'] === $lead->id)) {
                                continue;
                            }
                            $named++;
                            $this->option('dry-run') || $lead->forceFill(['contact_name' => mb_substr($who['name'], 0, 191)])->saveQuietly();
                        }
                    });

                $tagged = 0;
                WebChat::query()->whereNotNull('lead_id')->whereNotNull('page_url')->get(['lead_id', 'page_url'])
                    ->each(function (WebChat $chat) use (&$tagged) {
                        $referral = LeadReferral::fromUrl($chat->page_url);
                        if ($referral !== null) {
                            $tagged += $this->option('dry-run')
                                ? Lead::query()->whereKey($chat->lead_id)->whereNull('referral')->count()
                                : Lead::query()->whereKey($chat->lead_id)->whereNull('referral')->update(['referral' => json_encode($referral)]);
                        }
                    });

                if ($named + $tagged > 0) {
                    $this->line("{$orgId}: {$named} named, {$tagged} referral(s)");
                }
            });
        }

        return self::SUCCESS;
    }
}
