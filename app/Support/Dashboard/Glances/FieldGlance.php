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
            $openByKind = FieldAlert::query()->whereIn('user_id', $ids)->whereNull('acknowledged_at')
                ->selectRaw('kind, COUNT(*) as n')->groupBy('kind')->pluck('n', 'kind');
            $open = (int) $openByKind->sum();
            $tracked = count($ids);
            $count = fn (string $kind) => (int) ($openByKind[$kind] ?? 0);
            $people = fn (int $n) => $n === 1 ? '1 person' : "{$n} people";

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
                // What needs a manager, unacknowledged on the Field dashboard:
                // an SOS and a silent phone are bad (somebody may be in
                // trouble), standing still too long is caution.
                'attention' => array_values(array_filter([
                    $count(FieldAlert::SOS) > 0
                        ? GlancePayload::attention('bad', ($count(FieldAlert::SOS) === 1 ? '1 SOS' : $count(FieldAlert::SOS).' SOS alerts').' waiting')
                        : null,
                    $count(FieldAlert::SIGNAL_LOST) > 0
                        ? GlancePayload::attention('bad', $people($count(FieldAlert::SIGNAL_LOST)).' lost signal')
                        : null,
                    $count(FieldAlert::STOPPED) > 0
                        ? GlancePayload::attention('caution', $people($count(FieldAlert::STOPPED)).' stopped too long')
                        : null,
                ])),
            ]);
        });
    }
}
