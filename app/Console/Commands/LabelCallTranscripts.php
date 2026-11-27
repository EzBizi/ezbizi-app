<?php

namespace App\Console\Commands;

use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Ai\TranscriptSpeakers;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Splits each new call transcript in Leads into IVR menu / Dispatcher /
 * Customer (Jose, 2026-10-12). The raw words stay in `transcript`; an answer
 * that cannot be trusted stores the raw words here too, so the call is not
 * asked about again and the thread shows what Whisper heard.
 */
class LabelCallTranscripts extends Command
{
    protected $signature = 'ezbizi:label-call-transcripts {--limit=10} {--days=7}';

    protected $description = 'Divide new call transcripts by speaker (IVR menu, Dispatcher, Customer) with Grok';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        if (! config('ezbizi.leads_ai_grok') || ! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $rows = LeadConversation::query()->withoutGlobalScopes()
            ->whereNotNull('transcript')
            // read() always sets signals: null means not read yet, including calls
            // 0.0.0410 split by speaker before summaries existed.
            ->whereNull('call_signals')
            ->where('occurred_at', '>=', max(now()->subDays((int) $this->option('days')), GrokCli::since()))
            ->orderByDesc('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        // Side by side (GrokCli::askMany); a failed call stays unlabelled and
        // is asked about again next run.
        $reads = TranscriptSpeakers::readMany($rows->map(fn ($row) => (string) $row->transcript)->all(), $rows->pluck('organization_id')->all());

        foreach ($rows->values() as $n => $row) {
            $read = $reads[$n];
            if ($read instanceof RuntimeException) {
                $this->warn('Grok: '.$read->getMessage());

                continue;
            }
            $tenant->run(
                Organization::withoutGlobalScopes()->findOrFail($row->organization_id),
                function () use ($row, $read, $events) {
                    $row->forceFill([
                        'transcript_labelled' => $read['labelled'] ?? $row->transcript,
                        'call_summary' => $read['summary'],
                        'call_next_step' => $read['next_step'],
                        'call_signals' => $read['signals'],
                    ])->save();

                    // Neither booked nor a complaint: a recovery text may follow (2026-11-03).
                    if (! $read['signals']['complaint'] && ! $read['signals']['booked'] && $row->lead !== null
                        && $row->occurred_at?->gte(now()->subDay())
                        // Once a day per lead: one call can be stored as two transcripts.
                        && ! \App\Models\AutomationEvent::query()->where('event_key', 'lead.call_not_booked')
                            ->where('subject_id', $row->lead->getKey())->where('occurred_at', '>=', now()->subDay())->exists()) {
                        $events->emit('lead.call_not_booked', $row->lead, ['after' => [
                            'summary' => $read['summary'],
                            'caller_name' => $row->lead->contact_name,
                            'phone' => $row->lead->phone,
                        ]]);
                    }

                    // Jose, 2026-10-12: "notify lead manager and administrator" -
                    // who hears is the Automations rule's, so it can change.
                    // Only a fresh call: the first run reads a week of older ones.
                    if ($read['signals']['complaint'] && $row->lead !== null
                        && $row->occurred_at?->gte(now()->subDay())
                        // One alert per lead per call: a transferred call is two
                        // legs, two transcripts (prod 10-28: 1.5 min apart).
                        && Cache::add('lead.call_complaint:'.$row->lead_id, true, now()->addMinutes(15))) {
                        $events->emit('lead.call_complaint', $row->lead, ['after' => [
                            'summary' => $read['summary'],
                            'caller_name' => $row->lead->contact_name,
                            'phone' => $row->lead->phone,
                        ]]);
                    }
                },
            );
        }

        return self::SUCCESS;
    }
}
