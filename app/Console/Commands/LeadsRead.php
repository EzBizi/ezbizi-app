<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Services\Leads\LeadInsights;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Grok reads new leads off the sync's back (Grok takes ~8 s a call): the
 * pile and one line, and the name and ZIP a call or message gave that the
 * source did not (Jose, 2026-10-09: "fill in the gaps, like in the LS Calls").
 * A call is read once its transcript exists. Never overwrites what we hold.
 */
class LeadsRead extends Command
{
    protected $signature = 'ezbizi:leads-read {--limit=12}';

    protected $description = 'Sort new leads and fill a missing name or ZIP from what the customer said';

    public function handle(LeadInsights $insights, TenantContext $tenant, EventEmitter $events): int
    {
        if ($insights->inline() || ! $insights->enabled()) {
            return self::SUCCESS;   // Bedrock sorts inline; nothing configured, nothing to do
        }

        $leads = Lead::query()->withoutGlobalScopes()
            ->whereNull('classification')
            ->where('is_test', false)
            ->where('received_at', '>=', now()->subDays(3))
            // Something said in words: a message, or a call already transcribed.
            ->whereExists(fn ($q) => $q->from('lead_conversations')
                ->whereColumn('lead_conversations.lead_id', 'leads.id')
                ->where('participant', LeadConversation::FROM_CONSUMER)
                ->whereRaw("COALESCE(NULLIF(TRIM(text), ''), NULLIF(TRIM(transcript), '')) IS NOT NULL"))
            ->orderByDesc('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $read = 0;
        foreach ($leads->groupBy('organization_id') as $orgId => $group) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($group, $insights, $events, &$read) {
                foreach ($group as $lead) {
                    try {
                        $answer = $insights->read($lead);
                    } catch (RuntimeException $e) {
                        $this->warn('Grok: '.$e->getMessage());

                        return;   // an expired sign-in fails every call; try the rest next run
                    }
                    if ($answer === null) {
                        continue;
                    }

                    // Spam, a vendor, a wrong number: tagged Not a lead so it
                    // leaves the queue (Jose, 2026-10-09: "check the forms ...
                    // and tag them appropriately ... this one is clearly not a
                    // lead"). Only a lead nobody has decided on yet.
                    $before = $lead->status;
                    $junk = $answer['classification'] === LeadInsights::ARCHIVE && $before === Lead::STATUS_NEW;

                    $lead->forceFill([
                        'status' => $junk ? Lead::STATUS_NOT_A_LEAD : $before,
                        'classification' => $answer['classification'],
                        'summary' => $answer['summary'] !== '' ? $answer['summary'] : $lead->summary,
                        'classified_at' => now(),
                        'contact_name' => $lead->contact_name ?: $answer['name'],
                        'postal_code' => $lead->postal_code ?: $answer['zip'],
                    ])->save();

                    if ($junk) {
                        $events->emit('lead.status_changed', $lead, [
                            'before' => ['status' => $before],
                            'after' => ['status' => Lead::STATUS_NOT_A_LEAD],
                        ]);
                    }

                    $events->emit('lead.classified', $lead, ['after' => [
                        'classification' => $lead->classification,
                        'summary' => $lead->summary,
                        'contact_name' => $lead->contact_name,
                        'phone' => $lead->phone,
                    ]]);
                    $read++;
                }
            });
        }

        $this->info("Read {$read} lead(s).");

        return self::SUCCESS;
    }
}
