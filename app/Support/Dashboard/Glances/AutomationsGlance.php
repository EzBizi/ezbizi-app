<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\User;
use App\Support\Automations\EventScope;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
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
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            // The rules this person's view lists, asked the way the
            // Automations list asks (AutomationsController::index), so the box
            // never counts a sibling company's rules (2026-10-16).
            $view = ViewScope::forUser($user);
            $chain = EventScope::chainFor($view->isOrganization() ? null : $view->type, $view->id);
            $visible = Automation::query()->get()
                ->filter(fn (Automation $a) => $view->isOrganization()
                    || $a->coversScope($view->type, $view->id, companyChain: $chain));
            $ids = $visible->modelKeys();
            // The event queue is the whole organization's, so only somebody
            // standing there is told how far behind it is.
            $wholeQueue = $view->isOrganization();

            $recent = AutomationRun::query()
                ->whereIn('automation_id', $ids)
                ->orderByDesc('started_at')
                ->limit(100)
                ->pluck('status');

            $waiting = $wholeQueue ? AutomationEvent::query()->whereNull('delivered_at')->count() : 0;

            // No date: the organization chooses its date format and that lives in
            // the client, so a stamp composed here would be written in the wrong
            // one. The card carries the WHEN; this box carries the THAT.
            $off = $visible->whereNotNull('disabled_at')->pluck('name');
            $stopped = match (true) {
                $off->isEmpty() => null,
                $off->count() === 1 => "'{$off->first()}' stopped itself and is still off.",
                default => "{$off->count()} automations stopped themselves and are still off.",
            };

            if ($recent->isEmpty()) {
                $sentence = 'No automations have run.';
                if ($waiting === 1) {
                    $sentence .= ' 1 event is waiting.';
                } elseif ($waiting > 1) {
                    $sentence .= " {$waiting} events are waiting.";
                }
                // Concatenated rather than added to `sentences`: on the empty
                // branch the client shows `sentence` alone and silently drops
                // anything else, so a second key here would render as nothing.
                if ($stopped !== null) {
                    $sentence .= ' '.$stopped;
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

            $sentences = [];
            if ($waiting === 1) {
                $sentences[] = '1 event is waiting.';
            } elseif ($waiting > 1) {
                $sentences[] = "{$waiting} events are waiting.";
            }
            // Appended, never assigned: the waiting line used to own this key
            // outright, so adding a second writer by assignment would have
            // dropped whichever ran first.
            if ($stopped !== null) {
                $sentences[] = $stopped;
            }
            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            // From what the box already counts: the last 100 runs and the rules
            // that stopped themselves. Failures lead, as the bad number does on
            // Business Profile. Snapshot, so no previous.
            $data['figures'] = [
                GlancePayload::figure('failed', 'runs failed', $failed),
                GlancePayload::figure('succeeded', 'runs succeeded', $succeeded),
                GlancePayload::figure('stopped', 'stopped', $off->count()),
            ];

            // WHAT NEEDS SOMEBODY, counts only, at most three. Bad: a rule that
            // switched itself off, or events the engine has not picked up for
            // a quarter of an hour (the worker is behind or stopped). Caution:
            // a live rule that has failed enough times in a row to be close to
            // switching itself off.
            $stuck = ! $wholeQueue ? 0 : AutomationEvent::query()->whereNull('delivered_at')
                ->where('available_at', '<', now()->subMinutes(15))->count();
            $nearly = Automation::query()->whereIn('id', $ids)->where('is_active', true)->whereNull('disabled_at')
                ->where('consecutive_failures', '>=', Automation::FAILURE_LIMIT - 2)->count();

            $attention = array_values(array_filter([
                $off->isEmpty() ? null : GlancePayload::attention('bad', $off->count() === 1
                    ? '1 automation stopped itself'
                    : "{$off->count()} automations stopped themselves"),
                $stuck === 0 ? null : GlancePayload::attention('bad', $stuck === 1
                    ? '1 event waiting over 15 minutes'
                    : "{$stuck} events waiting over 15 minutes"),
                $nearly === 0 ? null : GlancePayload::attention('caution', $nearly === 1
                    ? '1 automation close to stopping itself'
                    : "{$nearly} automations close to stopping themselves"),
            ]));
            if ($attention !== []) {
                $data['attention'] = $attention;
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
