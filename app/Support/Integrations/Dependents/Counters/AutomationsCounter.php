<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\Automation;
use App\Models\User;
use App\Support\Integrations\Dependents\DependentCounter;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;

/**
 * Live automations that stop doing their job.
 *
 * Two ways a rule depends on a connection:
 *   · its saved actions/conditions name this connection's id (a picked
 *     account, a Mailchimp audience bound to it, …) — always counted;
 *   · it uses an action or trigger that needs SOME account of this provider
 *     (workiz.job_update needs a Workiz connection; phone.* triggers need a
 *     phone account) — counted only when this is the organization's last one,
 *     because another connection of the same provider keeps the rule working.
 */
final class AutomationsCounter implements DependentCounter
{
    /**
     * provider => [action types, trigger-key prefixes].
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const NEEDS = [
        'workiz' => [['workiz.job_update', 'inspection_report.workiz'], ['workiz.']],
        'mailchimp' => [['add_to_audience'], []],
        'google_business_profile' => [['draft_reply', 'publish_reply'], ['review.', 'business_location.']],
        'google_ads' => [[], ['ads.']],
        'facebook_ads' => [[], ['ads.']],
        'whatsapp' => [['whatsapp.send', 'whatsapp.group_send'], ['whatsapp.']],
        'thumbtack' => [['reply'], []],
        'twilio' => [['sms.send', 'lead.text', 'report.text', 'message.reply', 'missed.create_job', 'voicemail.mark_dealt'], ['phone.']],
        'google_workspace' => [['message.mark_dealt'], ['email.']],
    ];

    public function count(IntegrationRef $ref, User $viewer): ?array
    {
        [$actions, $triggers] = self::NEEDS[$ref->provider] ?? [[], []];

        $hits = Automation::query()
            ->where('organization_id', $ref->organizationId)
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->whereNotNull('active_version_id')
            ->with('activeVersion')
            ->orderBy('name')
            ->get()
            ->filter(function (Automation $rule) use ($ref, $actions, $triggers) {
                $version = $rule->activeVersion;
                $steps = $version?->actions ?? [];
                $saved = json_encode([$steps, $version?->conditions ?? []]);

                if (is_string($saved) && str_contains($saved, $ref->id)) {
                    return true;
                }

                if (! $ref->lastOfProvider) {
                    return false;
                }

                foreach ($triggers as $prefix) {
                    if (str_starts_with((string) $rule->trigger_key, $prefix)) {
                        return true;
                    }
                }

                foreach ($steps as $step) {
                    if (in_array($step['type'] ?? null, $actions, true)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        $n = $hits->count();

        return [
            'key' => 'automations',
            'label' => $n === 1 ? '1 active automation' : "{$n} active automations",
            'count' => $n,
            'names' => $viewer->hasPermission('automations.view')
                ? $hits->take(5)->pluck('name')->map(fn ($v) => (string) $v)->all()
                : [],
        ];
    }

    public function features(IntegrationRef $ref): array
    {
        if (! isset(self::NEEDS[$ref->provider])) {
            return [];
        }

        $name = Feature::providerLabel($ref);

        return [Feature::lastBreaks($ref, "Automations: steps that need {$name} stop running")];
    }
}
