<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Every five minutes: raise `lead.follow_up_due` once for a customer who got
 * the automatic first reply and went quiet, so a rule can send ONE follow-up
 * (Jose, 2026-09-30: Rodrigo's bot "is also doing a follow up after the first
 * attempt", "should only happen once of course per customer").
 *
 * Ported from a1leads `pipeline/automations/followup.js`, same guards:
 *  - every business message so far was sent by a rule - a person who replied
 *    has taken the conversation over;
 *  - the customer has written nothing since the first of them;
 *  - that first one is leads.follow_up_hours old, and it is still the same day
 *    where the business is (no "just following up" at midnight);
 *  - the lead is still New, and what the customer wrote is not a complaint or
 *    a cancellation;
 *  - never twice: `follow_up_raised_at`.
 */
class LeadsFollowUpDue extends Command
{
    protected $signature = 'ezbizi:leads-follow-up-due';

    protected $description = 'Raise lead.follow_up_due for customers who went quiet after the automatic first reply';

    /** From a1leads' SERVICE_RECOVERY_RE: a nudge here would be insensitive. */
    private const NOT_A_SALES_CONVERSATION = '/\b(?:no[- ]?show|no one showed|nobody showed|never showed|didn\'?t show|did not show|missed (?:my|our|the|an?) ?appointment|never arrived|cancel(?:led|ed|ling|ing)?|refund|complaint|unacceptable|dissatisfied|rude|waste of (?:my|our) time)\b/i';

    public function handle(TenantContext $tenant, SettingsResolver $settings, EventEmitter $events): int
    {
        $raised = 0;

        $candidates = Lead::query()->withoutGlobalScopes()->whereNull('hidden_at')
            ->whereIn('source', Lead::INBOX_SOURCES)
            ->where('status', Lead::STATUS_NEW)
            ->whereNull('follow_up_raised_at')
            // Not last_contact_from: LeadThread leaves it on the customer after an
            // automatic reply (only a person answers), so asking for ADVERTISER here
            // matched no lead on production, ever (desk, 2026-11-02). Who spoke last is
            // firstReplyIfQuiet's to decide, from the rows.
            ->whereBetween('last_contact_at', [now()->subDay(), now()->subHour()]) // each org's own hours below
            ->get();

        foreach ($candidates->groupBy('organization_id') as $organizationId => $leads) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($organization === null) {
                continue;
            }
            $tenant->run($organization, function () use ($leads, $organization, $settings, $events, &$raised) {
                $zone = (string) $settings->get('timezone', $organization);
                foreach ($leads as $lead) {
                    $firstReply = $this->firstReplyIfQuiet($lead);
                    if ($firstReply === null
                        || $firstReply->occurred_at->gt(now()->subHours((int) SettingsResolver::forOrganization('leads.follow_up_hours')))
                        || $firstReply->occurred_at->copy()->tz($zone)->toDateString() !== now()->tz($zone)->toDateString()) {
                        continue;
                    }

                    $lead->forceFill(['follow_up_raised_at' => now()])->save();
                    $first = Lead::firstNameOf($lead->contact_name);
                    $events->emit('lead.follow_up_due', $lead, [
                        'after' => [
                            'source' => $lead->source,
                            'kind' => $lead->kind(),
                            'contact_name' => $lead->contact_name,
                            'first_name' => $first,
                            'greeting' => Lead::greeting($first),
                            // The after-call text already went, so a rule can stay at one
                            // booking link (Jose, 2026-11-05: "send only one").
                            'not_booked_call' => AutomationEvent::query()->where('event_key', 'lead.call_not_booked')
                                ->where('subject_id', $lead->getKey())->exists(),
                            // The missed-call text already went: no went-quiet text after it (desk for Jose, 2026-11-16).
                            'missed_call' => AutomationEvent::query()->where('event_key', 'lead.missed_call')
                                ->where('subject_id', $lead->getKey())->exists(),
                            'hours_since_reply' => (int) $firstReply->occurred_at->diffInHours(now()),
                        ],
                    ]);
                    $raised++;
                }
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }

    /** The first automatic reply, when nothing a person or the customer said came after it. */
    private function firstReplyIfQuiet(Lead $lead): ?LeadConversation
    {
        $rows = $lead->conversations()->orderBy('occurred_at')->get();
        $ours = $rows->where('participant', LeadConversation::FROM_ADVERTISER);
        $first = $ours->first();

        if ($first === null || $ours->contains(fn ($c) => ! $c->is_automatic)) {
            return null;
        }
        $theirs = $rows->where('participant', LeadConversation::FROM_CONSUMER);
        if ($theirs->isEmpty()
            || $theirs->contains(fn ($c) => $c->occurred_at->gt($first->occurred_at))
            || preg_match(self::NOT_A_SALES_CONVERSATION, $theirs->pluck('text')->implode("\n"))) {
            return null;
        }

        return $first;
    }
}
