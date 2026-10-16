<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Services\Leads\LeadInsights;
use App\Support\Ai\NameDecision;
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
            ->where('received_at', '>=', max(now()->subDays(3), \App\Support\Ai\GrokCli::since()))
            // Something said in words: a message, or a call already transcribed.
            ->whereExists(fn ($q) => $q->from('lead_conversations')
                ->whereColumn('lead_conversations.lead_id', 'leads.id')
                ->where('participant', LeadConversation::FROM_CONSUMER)
                // A call's text is our tag, not its words (RingCentralLeadCalls).
                ->whereRaw("COALESCE(NULLIF(TRIM(CASE WHEN channel = 'PHONE_CALL' THEN NULL ELSE text END), ''), NULLIF(TRIM(transcript), '')) IS NOT NULL"))
            ->orderByDesc('received_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $read = 0;
        foreach ($leads->groupBy('organization_id') as $orgId => $group) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($group, $insights, $events, &$read) {
                // Side by side (GrokCli::askMany); a failed lead stays unread
                // and is asked again next run.
                $answers = $insights->readMany($group->values()->all());

                foreach ($group->values() as $n => $lead) {
                    $answer = $answers[$n];
                    if ($answer instanceof RuntimeException) {
                        $this->warn('Grok: '.$answer->getMessage());

                        continue;
                    }
                    if ($answer === null) {
                        continue;
                    }

                    // Spam, a vendor, a wrong number: tagged Not a lead so it
                    // leaves the queue (Jose, 2026-10-09: "check the forms ...
                    // and tag them appropriately ... this one is clearly not a
                    // lead"). Only a lead nobody has decided on yet.
                    $before = $lead->status;
                    // Its own flag, not ARCHIVE: Grok files a declined quote or a
                    // service we may not offer as ARCHIVE too, and those are people.
                    $applicant = $answer['job_applicant'] && $before === Lead::STATUS_NEW;
                    $junk = ! $applicant && $answer['not_a_lead'] && $before === Lead::STATUS_NEW;
                    $lost = ! $applicant && ! $junk && $answer['lost'] && $before === Lead::STATUS_NEW;
                    // Booked on the call (Jose, 2026-10-13, Rodrigo's verdict
                    // with its booking gate - LeadInsights::gate).
                    $booked = ! $applicant && ! $junk && ! $lost && $answer['booked'] && $before === Lead::STATUS_NEW;
                    $decided = $applicant || $junk || $lost || $booked;

                    // Rodrigo's §3 merge table (NameDecision): fill a blank,
                    // enrich a first name, correct AI's own weak spelling; a
                    // name a person set, or a shared phone, is only flagged.
                    [$nameAction, $name] = NameDecision::decide(
                        $lead->contact_name,
                        $lead->ai_set['contact_name'] ?? null,
                        $answer['name'],
                        $answer['name_basis'] ?? 'self_introduction',
                        $answer['name'] !== null && self::phoneShared($lead, $answer['name']),
                    );

                    // What AI wrote, so the AI mark shows while the value is
                    // still AI's and the weekly check counts what people changed.
                    $aiSet = array_filter([
                        'status' => $decided ? match (true) {
                            $applicant => Lead::STATUS_JOB_APPLICANT,
                            $junk => Lead::STATUS_NOT_A_LEAD,
                            $booked => Lead::STATUS_BOOKED,
                            default => Lead::STATUS_LOST,
                        } : null,
                        // Why, shown on the AI mark.
                        'reason' => $decided ? $answer['reason'] : null,
                        'contact_name' => in_array($nameAction, ['fill', 'enrich', 'correct'], true) ? $name : null,
                        'name_conflict' => $nameAction === 'conflict' ? $name : null,
                        'postal_code' => $lead->postal_code ? null : $answer['zip'],
                    ], fn ($v) => $v !== null && $v !== '');

                    $lead->forceFill([
                        'ai_set' => $aiSet !== [] ? ['at' => now()->toIso8601String()] + $aiSet : null,
                        'status' => match (true) {
                            $applicant => Lead::STATUS_JOB_APPLICANT,
                            $junk => Lead::STATUS_NOT_A_LEAD,
                            $lost => Lead::STATUS_LOST,
                            $booked => Lead::STATUS_BOOKED,
                            default => $before,
                        },
                        // Google Local Services only: Google refunds its own leads.
                        'credit_reason' => $lead->source === Lead::SOURCE_LOCAL_SERVICES ? $answer['google_credit'] : null,
                        'classification' => $answer['classification'],
                        'summary' => $answer['summary'] !== '' ? $answer['summary'] : $lead->summary,
                        'classified_at' => now(),
                        'contact_name' => in_array($nameAction, ['fill', 'enrich', 'correct'], true) ? $name : $lead->contact_name,
                        'postal_code' => $lead->postal_code ?: $answer['zip'],
                    ])->save();

                    if ($decided) {
                        $events->emit('lead.status_changed', $lead, [
                            'before' => ['status' => $before],
                            'after' => ['status' => $lead->status],
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

    /** Another lead on this phone goes by a different name: one number, maybe two people. */
    private static function phoneShared(Lead $lead, string $name): bool
    {
        if (blank($lead->phone_digits)) {
            return false;
        }

        return Lead::query()->withoutGlobalScopes()
            ->where('organization_id', $lead->organization_id)
            ->where('phone_digits', $lead->phone_digits)
            ->whereKeyNot($lead->getKey())
            ->whereNotNull('contact_name')
            ->whereRaw('LOWER(contact_name) <> ?', [mb_strtolower($name)])
            ->exists();
    }
}
