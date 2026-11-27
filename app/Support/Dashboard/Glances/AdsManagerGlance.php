<?php

namespace App\Support\Dashboard\Glances;

use App\Models\AdSchedule;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;

/**
 * Marketing › Ads box (2026-11-09): the Google and Meta ad accounts in view,
 * the campaigns on an on/off schedule, and the schedules whose last switch
 * failed. Read from our own rows, never from Google or Meta on a dashboard
 * load, and counts only - no spend or budget (Jose, 2026-09-29: no financial
 * info on the dashboard).
 */
final class AdsManagerGlance extends DescribedGlance
{
    protected const KEY = 'ads_manager';

    protected const LABEL = 'Ads';

    protected const PERMISSION = 'ads_manager.view';

    protected const PRESENTATIONS = ['text'];

    protected const DEFAULT_PRESENTATION = 'text';

    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $access = app(SocialConnectionAccess::class);
            $accounts = SocialConnection::query()
                ->whereIn('provider', [SocialProvider::GoogleAds->value, SocialProvider::FacebookAds->value])
                ->where('status', SocialConnection::STATUS_CONNECTED)
                ->get()
                // A manager holds accounts, not campaigns.
                ->filter(fn (SocialConnection $c) => ! $c->is_manager && $access->inView($user, $c));

            if ($accounts->isEmpty()) {
                return $this->payload(['empty' => true, 'sentence' => 'No Google or Meta ad accounts to show.']);
            }

            $google = $accounts->where('provider', SocialProvider::GoogleAds->value)->count();
            $meta = $accounts->count() - $google;
            $schedules = AdSchedule::query()->whereIn('connection_id', $accounts->map->getKey())->get(['id', 'last_error']);
            $failed = $schedules->filter(fn (AdSchedule $s) => filled($s->last_error))->count();

            return $this->payload([
                'empty' => false,
                'sentences' => array_values(array_filter([
                    $failed === 0 ? null : ($failed === 1 ? '1 schedule could not switch its campaign.' : "{$failed} schedules could not switch their campaigns."),
                    "{$google} Google and {$meta} Meta ".($accounts->count() === 1 ? 'account' : 'accounts').' connected.',
                    $schedules->isEmpty() ? null : ($schedules->count() === 1 ? '1 campaign on a schedule.' : "{$schedules->count()} campaigns on a schedule."),
                ])),
                'figures' => [
                    GlancePayload::figure('google', 'Google accounts', $google),
                    GlancePayload::figure('meta', 'Meta accounts', $meta),
                    GlancePayload::figure('scheduled', 'campaigns on a schedule', $schedules->count()),
                ],
                ...($failed === 0 ? [] : ['attention' => [GlancePayload::attention('bad', $failed === 1
                    ? '1 schedule failed to switch'
                    : "{$failed} schedules failed to switch")]]),
            ]);
        });
    }
}
