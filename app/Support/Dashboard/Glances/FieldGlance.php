<?php

namespace App\Support\Dashboard\Glances;

use App\Models\FieldAlert;
use App\Models\FieldPresence;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Field\FieldTracking;
use App\Support\Tenancy\TenantContext;

/** Field on the dashboard: who is out, and anything waiting for a manager. */
final class FieldGlance extends DescribedGlance
{
    protected const KEY = 'field';

    protected const LABEL = 'Field';

    protected const PERMISSION = 'field.dashboard.view';

    protected const PRESENTATIONS = ['text'];

    protected const DEFAULT_PRESENTATION = 'text';

    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $ids = app(FieldTracking::class)->trackedPeople($user)->modelKeys();

            if ($ids === []) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'Nobody you can see is tracked. Add Field › Tracked to a role '
                        .'in Settings › Roles & permissions.',
                ]);
            }

            $reporting = FieldPresence::query()->whereIn('user_id', $ids)
                ->where('last_seen_at', '>', now()->subMinutes(30))->count();
            $open = FieldAlert::query()->whereIn('user_id', $ids)->whereNull('acknowledged_at')->count();
            $tracked = count($ids);

            return $this->payload([
                'empty' => false,
                'sentences' => array_values(array_filter([
                    "{$reporting} of {$tracked} tracked ".($tracked === 1 ? 'person is' : 'people are').' reporting now.',
                    $open > 0 ? ($open === 1 ? '1 alert is waiting.' : "{$open} alerts are waiting.") : null,
                ])),
                'figures' => [
                    GlancePayload::figure('tracked', 'tracked', $tracked),
                    GlancePayload::figure('reporting', 'reporting now', $reporting),
                    GlancePayload::figure('alerts', 'alerts waiting', $open),
                ],
            ]);
        });
    }

    /** @param array<string, mixed> $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }
}
