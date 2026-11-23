<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\Onboarding;
use App\Models\Organization;
use App\Models\User;
use App\Services\Hr\OnboardingForm;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Onboardings left unfinished, told to the hire's branch (Merav, 2026-11-19:
 * "Missing Information Warning for people who have not completed the
 * Onboardings (Alerts for Local Manager)").
 *
 * An onboarding still being filled in after `hr.alerts.quiet_days` with
 * required answers missing is sent, once per that many days, to the active
 * people placed at the hire's branch who may read HR records. No branch, no
 * alert: organization-level HR sees it on the HR dashboard already.
 */
class HrOnboardingGapAlerts extends Command
{
    protected $signature = 'ezbizi:hr-onboarding-gap-alerts';

    protected $description = 'Tell branch managers which unfinished onboardings are missing information';

    public const KIND = 'hr.onboarding_missing';

    public function handle(TenantContext $tenant, NotificationDispatcher $notify, OnboardingForm $form, SettingsResolver $settings): int
    {
        $sent = 0;
        $orgs = Onboarding::query()->withoutGlobalScopes()->where('status', Onboarding::IN_PROGRESS)
            ->pluck('organization_id')->unique();

        foreach (Organization::query()->whereIn('id', $orgs)->get() as $organization) {
            $sent += $tenant->run($organization, function () use ($organization, $notify, $form, $settings) {
                $quiet = max(1, (int) $settings->get('hr.alerts.quiet_days', $organization));
                $sent = 0;

                $open = Onboarding::query()->with(['user:id,name', 'invitation'])
                    ->where('status', Onboarding::IN_PROGRESS)
                    ->where('started_at', '<=', now()->subDays($quiet))->get();

                foreach ($open as $onboarding) {
                    $branch = $onboarding->branch();
                    $missing = $form->missing($onboarding);
                    if ($branch === null || $missing === []
                        || ! Cache::add("hr-onboarding-gap:{$onboarding->id}", 1, now()->addDays($quiet))) {
                        continue;
                    }

                    $managers = User::query()->where('is_active', true)
                        ->whereIn('id', Assignment::query()->where('assigned_franchise_id', $branch->id)->select('user_id'))
                        ->where('id', '!=', $onboarding->user_id)
                        ->get()->filter(fn (User $u) => $u->hasPermission('hr.records.view'));

                    $who = $onboarding->user?->name ?? 'A new hire';
                    $list = array_values($missing);
                    $body = 'Still missing: '.implode(', ', array_slice($list, 0, 8))
                        .(count($list) > 8 ? ' and '.(count($list) - 8).' more' : '').'.';

                    foreach ($managers as $manager) {
                        $sent += $notify->dispatch(
                            title: "{$who}'s onboarding is missing information ({$branch->name})",
                            body: $body, subject: $onboarding, onlyTo: $manager, source: 'hr', kind: self::KIND,
                        ) > 0 ? 1 : 0;
                    }
                }

                return $sent;
            });
        }

        $this->info("Sent {$sent} onboarding gap alerts.");

        return self::SUCCESS;
    }
}
