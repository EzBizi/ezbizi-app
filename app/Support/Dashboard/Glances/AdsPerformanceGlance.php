<?php

namespace App\Support\Dashboard\Glances;

use App\Models\AdsAccountState;
use App\Models\SocialConnection;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;

/**
 * Google Ads box: are the Local Services accounts in view still able to show?
 *
 * Read from what the watchers stored (`ezbizi:ads-certifications` daily,
 * `ezbizi:ads-campaign-watch` hourly), never from Google on a dashboard load -
 * forty accounts is forty requests, and spend stays in the tab that asks for
 * it. The bad numbers lead, as in the Business Profile box.
 */
final class AdsPerformanceGlance extends DescribedGlance
{
    protected const KEY = 'ads_performance';
    protected const LABEL = 'Google Ads';
    protected const PERMISSION = 'ads_performance.view';
    protected const PRESENTATIONS = ['text'];
    protected const DEFAULT_PRESENTATION = 'text';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $access = app(SocialConnectionAccess::class);
            $ids = SocialConnection::query()
                ->where('provider', SocialProvider::GoogleAds->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)
                ->get()
                ->filter(fn (SocialConnection $c) => $access->inView($user, $c))
                ->map(fn (SocialConnection $c) => $c->getKey());

            if ($ids->isEmpty()) {
                return $this->payload(['empty' => true, 'sentence' => 'No Google Ads accounts to show.']);
            }

            $states = AdsAccountState::query()->whereIn('connection_id', $ids)->get();
            $certification = $states->filter(fn (AdsAccountState $s) => $s->certification_problem_count > 0)->count();
            $paused = $states->sum(fn (AdsAccountState $s) => collect((array) $s->campaigns)
                ->filter(fn ($c) => ($c['status'] ?? '') !== 'ENABLED')->count());
            $total = $ids->count();

            $sentences = array_values(array_filter([
                $certification === 0 ? null : ($certification === 1
                    ? '1 account has a certification problem.'
                    : "{$certification} accounts have a certification problem."),
                $paused === 0 ? null : ($paused === 1
                    ? '1 Local Services campaign is paused.'
                    : "{$paused} Local Services campaigns are paused."),
                $total === 1 ? '1 account connected.' : "{$total} accounts connected.",
            ]));

            // Counts of accounts and campaigns only — never spend or budget
            // (Jose, 2026-09-29: no financial info on the dashboard).
            return $this->payload([
                'empty' => false,
                'sentences' => $sentences,
                'figures' => [
                    GlancePayload::figure('accounts', 'accounts connected', $total),
                    GlancePayload::figure('certification', 'certification problems', $certification),
                    GlancePayload::figure('paused', 'campaigns paused', (int) $paused),
                ],
                // Paused is not here: Jose pauses campaigns on purpose.
                ...($certification === 0 ? [] : ['attention' => [GlancePayload::attention('bad', $certification === 1
                    ? '1 account with a certification problem'
                    : "{$certification} accounts with a certification problem")]]),
            ]);
        });
    }
}
