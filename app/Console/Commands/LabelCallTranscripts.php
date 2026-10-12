<?php

namespace App\Console\Commands;

use App\Models\LeadConversation;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Ai\TranscriptSpeakers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
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

    public function handle(TenantContext $tenant): int
    {
        if (! config('ezbizi.leads_ai_grok') || ! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $rows = LeadConversation::query()->withoutGlobalScopes()
            ->whereNotNull('transcript')->whereNull('transcript_labelled')
            ->where('occurred_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderByDesc('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($rows as $row) {
            try {
                $read = TranscriptSpeakers::read((string) $row->transcript);
            } catch (RuntimeException $e) {
                $this->warn('Grok: '.$e->getMessage());

                return self::SUCCESS;   // an expired sign-in fails every call; next run tries again
            }
            $tenant->run(
                Organization::withoutGlobalScopes()->findOrFail($row->organization_id),
                fn () => $row->forceFill([
                    'transcript_labelled' => $read['labelled'] ?? $row->transcript,
                    'call_summary' => $read['summary'],
                    'call_next_step' => $read['next_step'],
                    'call_signals' => $read['signals'],
                ])->save(),
            );
        }

        return self::SUCCESS;
    }
}
