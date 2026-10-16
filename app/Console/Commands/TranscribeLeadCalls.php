<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Services\Leads\CallTranscripts;
use App\Support\Leads\LeadThread;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Transcribe the last week's answered Local Services calls, a few at a time,
 * and let each lead be sorted (BOOK / SUPPORT / ARCHIVE) from what was said -
 * A1 Leads transcribes and sorts its calls; this is EzBizi's version.
 *
 * Two steps each run: collect the words Google has finished, then start the
 * next calls. A call is sent once: a refusal or a failed job is given up on
 * (`transcribed_at` set, no transcript) rather than paid for again.
 *
 * Does nothing until `services.google_speech.key` is set.
 */
class TranscribeLeadCalls extends Command
{
    protected $signature = 'ezbizi:transcribe-lead-calls {--limit=40 : calls to start per run} {--days=7} {--minutes=4 : how long a run on this server may take}';

    protected $description = 'Transcribe recorded Local Services calls and sort their leads';

    public function handle(CallTranscripts $transcripts, TenantContext $tenant, LeadThread $thread): int
    {
        if (! $transcripts->enabled()) {
            $this->info('Speech-to-Text is not configured; nothing to do.');

            return self::SUCCESS;
        }

        $finished = $started = $failed = 0;

        if ($transcripts->local()) {
            return $this->transcribeHere($transcripts, $tenant, $thread);
        }

        $pending = LeadConversation::query()->withoutGlobalScopes()
            ->whereNotNull('transcript_operation')->whereNull('transcribed_at')
            ->limit(500)->get();

        $this->perOrganization($pending, $tenant, function (LeadConversation $row) use ($transcripts, $thread, &$finished, &$failed) {
            try {
                $words = $transcripts->finish($row->transcript_operation);
            } catch (RuntimeException) {
                $row->forceFill(['transcribed_at' => now()])->save();
                $failed++;

                return;
            }
            if ($words === null) {
                return;
            }
            $row->forceFill(['transcript' => $words === '' ? null : $words, 'transcribed_at' => now()])->save();
            $finished++;

            // Sorts the lead from the words, once - the same path a message takes.
            if ($words !== '' && ($lead = Lead::query()->find($row->lead_id)) !== null) {
                $thread->refresh($lead);
            }
        });

        $next = LeadConversation::query()->withoutGlobalScopes()
            ->where('channel', LeadConversation::CHANNEL_PHONE_CALL)
            ->where('participant', LeadConversation::FROM_CONSUMER)
            ->whereNotNull('recording_url')
            // RingCentral calls take Reports' transcript (RingCentralLeadCalls::fillTranscripts).
            ->where('recording_url', 'not like', 'ringcentral:%')
            ->whereNotNull('duration_ms')          // rang out: nothing to hear
            ->whereNull('transcript_operation')->whereNull('transcribed_at')
            ->where('occurred_at', '>=', $this->oldest())
            ->orderByDesc('occurred_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $this->perOrganization($next, $tenant, function (LeadConversation $row) use ($transcripts, &$started, &$failed) {
            $connection = Lead::query()->find($row->lead_id)?->connection;
            try {
                if ($connection === null) {
                    throw new RuntimeException('The account is no longer connected.');
                }
                $row->forceFill(['transcript_operation' => $transcripts->start($row, $connection)])->save();
                $started++;
            } catch (RuntimeException) {
                $row->forceFill(['transcribed_at' => now()])->save();
                $failed++;
            }
        });

        $this->info("Finished {$finished}, started {$started}, gave up on {$failed}.");

        return self::SUCCESS;
    }

    /**
     * Whisper on this server: one call at a time, newest first, until the
     * run's time is spent - the timer starts the next run five minutes after
     * this one ends (deploy/ezbizi-transcribe.timer), so the box never runs
     * two at once.
     */
    private function transcribeHere(CallTranscripts $transcripts, TenantContext $tenant, LeadThread $thread): int
    {
        $until = now()->addMinutes((int) $this->option('minutes'));
        $done = $failed = 0;

        foreach ($this->untranscribed((int) $this->option('limit') * 10) as $row) {
            if (now()->gte($until)) {
                break;
            }
            $this->perOrganization(collect([$row]), $tenant, function (LeadConversation $row) use ($transcripts, $thread, &$done, &$failed) {
                $lead = Lead::query()->find($row->lead_id);
                try {
                    if ($lead?->connection === null) {
                        throw new RuntimeException('The account is no longer connected.');
                    }
                    $words = $transcripts->transcribeHere($row, $lead->connection);
                } catch (RuntimeException) {
                    $row->forceFill(['transcribed_at' => now()])->save();
                    $failed++;

                    return;
                }
                $row->forceFill(['transcript' => $words === '' ? null : $words, 'transcribed_at' => now()])->save();
                $done++;
                if ($words !== '') {
                    $thread->refresh($lead);
                }
            });
        }

        $this->info("Transcribed {$done} here, gave up on {$failed}.");

        return self::SUCCESS;
    }

    /** @return Collection<int, LeadConversation> answered calls with a recording, newest first */
    private function untranscribed(int $limit): Collection
    {
        return LeadConversation::query()->withoutGlobalScopes()
            ->where('channel', LeadConversation::CHANNEL_PHONE_CALL)
            ->where('participant', LeadConversation::FROM_CONSUMER)
            ->whereNotNull('recording_url')
            // RingCentral calls take Reports' transcript (RingCentralLeadCalls::fillTranscripts).
            ->where('recording_url', 'not like', 'ringcentral:%')
            ->whereNotNull('duration_ms')
            ->whereNull('transcript_operation')->whereNull('transcribed_at')
            ->where('occurred_at', '>=', $this->oldest())
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /** @param Collection<int, LeadConversation> $rows */
    private function perOrganization(Collection $rows, TenantContext $tenant, callable $each): void
    {
        foreach ($rows->groupBy('organization_id') as $organizationId => $group) {
            $organization = Organization::query()->withoutGlobalScopes()->find($organizationId);
            if ($organization !== null) {
                $tenant->run($organization, fn () => $group->each($each));
            }
        }
    }

    /**
     * The window, never before GROK_AI_SINCE (Jose, 2026-10-13: "just new
     * stuff from now on" - transcribing included). Read from config rather
     * than GrokCli::since() so this does not wait on that branch.
     */
    private function oldest(): \Illuminate\Support\Carbon
    {
        $since = \Illuminate\Support\Carbon::parse((string) config('services.grok_cli.since', '2026-10-13'))->startOfDay();

        return now()->subDays((int) $this->option('days'))->max($since);
    }
}
