<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadReplies;
use App\Support\Leads\LeadThread;
use App\Support\Notifications\LeadNotificationText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every minute: raise `lead.reply_overdue` for each customer message nobody
 * has answered in leads.reply_overdue_minutes, so a rule can tell the
 * dispatchers (Jose, 2026-09-28: "sometimes they may miss a lead or forget to
 * reply after the customer replies and the customer is left waiting").
 *
 * Once per customer message: `reply_overdue_raised_at` remembers the raise,
 * and a newer customer message starts the clock again. Only messages of the
 * last six hours - an overnight backlog is not ten-minute news, and the first
 * run after this ships must not raise one alert per old unanswered lead.
 */
class LeadsReplyOverdue extends Command
{
    protected $signature = 'ezbizi:leads-reply-overdue';

    protected $description = 'Raise lead.reply_overdue for customers left waiting for an answer';

    public function handle(TenantContext $tenant, LeadReplies $replies, EventEmitter $events): int
    {
        $raised = 0;

        $waiting = Lead::query()->withoutGlobalScopes()->whereNull('hidden_at')
            ->whereIn('source', Lead::INBOX_SOURCES)
            ->where('status', Lead::STATUS_NEW)
            ->where('last_contact_from', LeadConversation::FROM_CONSUMER)
            ->whereBetween('last_contact_at', [now()->subHours(6), now()->subMinute()]) // each org's own minutes: replyOverdue()
            ->where(fn ($q) => $q->whereNull('reply_overdue_raised_at')->orWhereColumn('reply_overdue_raised_at', '<', 'last_contact_at'))
            ->get();

        foreach ($waiting->groupBy('organization_id') as $organizationId => $leads) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($leads, $replies, $events, &$raised) {
                foreach ($leads as $lead) {
                    if (! $lead->replyOverdue($replies->canReply($lead))) {
                        continue;
                    }

                    $lead->forceFill(['reply_overdue_raised_at' => now()])->save();
                    $events->emit('lead.reply_overdue', $lead, [
                        'after' => [
                            'source' => $lead->source,
                            'kind' => $lead->kind(),
                            // Never blank: the warning starts with this word
                            // (2026-10-06, 22 sent as " has waited…").
                            'contact_name' => $lead->contact_name
                                ?: LeadNotificationText::phone($lead->phone)
                                ?: 'A customer',
                            'phone' => $lead->phone,
                            'minutes_waiting' => (int) $lead->last_contact_at->diffInMinutes(now()),
                            // What THEY said. last_contact_preview can be our own
                            // automatic reply, which leaves the lead unanswered
                            // (2026-10-08: the warning quoted "Thank you for
                            // reaching out to A-1 Chimney!").
                            'preview' => (($theirs = $lead->conversations()
                                ->reorder()
                                ->where('participant', LeadConversation::FROM_CONSUMER)
                                ->orderByDesc('occurred_at')
                                ->first()) ? LeadThread::preview($theirs) : null) ?? $lead->last_contact_preview,
                        ],
                    ]);
                    $raised++;
                }
            });
        }

        $this->info("Raised {$raised}.");

        return self::SUCCESS;
    }
}
