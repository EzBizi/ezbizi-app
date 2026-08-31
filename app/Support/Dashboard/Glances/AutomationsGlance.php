<?php

namespace App\Support\Dashboard\Glances;

use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Tenancy\TenantContext;

/**
 * Automations box: succeeded vs failed among the last 100 runs (snapshot).
 *
 * Organization-scoped — no franchise filter. Idle (no runs) is a sentence, not
 * 0/0 as health. Waiting undelivered events ride as a sentence on the same box.
 * Matched/skipped runs are skipped in the pie (succeeded vs failed only).
 */
final class AutomationsGlance extends DescribedGlance
{
    protected const KEY = 'automations';
    protected const LABEL = 'Automations';
    protected const PERMISSION = 'automations.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () {
            $recent = AutomationRun::query()
                ->orderByDesc('started_at')
                ->limit(100)
                ->pluck('status');

            $waiting = AutomationEvent::query()->whereNull('delivered_at')->count();

            if ($recent->isEmpty()) {
                $sentence = 'No automations have run.';
                if ($waiting === 1) {
                    $sentence .= ' 1 event is waiting.';
                } elseif ($waiting > 1) {
                    $sentence .= " {$waiting} events are waiting.";
                }

                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => $sentence,
                    ],
                );
            }

            $succeeded = $recent->filter(fn ($status) => $status === AutomationRun::SUCCEEDED)->count();
            $failed = $recent->filter(fn ($status) => $status === AutomationRun::FAILED)->count();

            $data = [
                'empty' => false,
                'slices' => [
                    ['label' => 'Succeeded', 'total' => $succeeded],
                    ['label' => 'Failed', 'total' => $failed],
                ],
            ];

            if ($waiting === 1) {
                $data['sentences'] = ['1 event is waiting.'];
            } elseif ($waiting > 1) {
                $data['sentences'] = ["{$waiting} events are waiting."];
            }

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
        });
    }
}
