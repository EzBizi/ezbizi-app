<?php

namespace App\Console\Commands;

use App\Models\CallReportTranscript;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\CallTranscripts;
use App\Support\Phone\NumberNames;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Transcripts for the Experimental call report (Jose, 2026-10-05: "check if
 * ring central covers it, if it's not free don't" - it is not - and "dont we
 * have transcription already in ezbizi, via whisper").
 *
 * Two sources, cheapest first:
 *
 *   1. THE SAME CALL AS A LOCAL SERVICES LEAD. Many A-1 calls arrive through
 *      Google Local Services too, and those are already transcribed by Whisper
 *      for Leads. Matched on the caller's number within three minutes -
 *      measured 2026-10-05: 271 of a week's first 1,000 RingCentral calls.
 *   2. WHISPER ON THIS SERVER, from RingCentral's recording - Leads' own runner
 *      (CallTranscripts::whisperAudio), so there is one way to run it.
 *
 * Only the calls the report's panels show: answered, recorded, on the New or
 * Existing client queues. Bounded by --minutes, and run after Leads' own
 * transcription by deploy/ezbizi-transcribe.service, never beside it.
 */
class TranscribeReportCalls extends Command
{
    protected $signature = 'ezbizi:transcribe-report-calls
        {--days=2 : days back to look, including today}
        {--minutes=3 : how long a run may take}
        {--organization= : whose report; defaults to the one organization with Workiz connected}';

    protected $description = 'Transcribe the Experimental call report\'s calls, reusing Local Services transcripts';

    /** Three minutes either side: RingCentral and Google stamp the same call a few seconds apart. */
    private const MATCH_SECONDS = 180;

    public function handle(RingCentralCallLog $calls, CallTranscripts $whisper, TenantContext $tenant): int
    {
        if (! config('experimental_reports.transcribe')) {
            $this->info('Call report transcription is switched off (REPORT_CALL_TRANSCRIPTS).');

            return self::SUCCESS;
        }

        if (! $calls->configured()) {
            $this->info('RingCentral is not configured.');

            return self::SUCCESS;
        }

        $organization = self::reportOrganization($this->option('organization'));

        if ($organization === null) {
            $this->warn('Which organization? Pass --organization; more or fewer than one has Workiz connected.');

            return self::FAILURE;
        }

        $zone = (string) config('app.timezone');
        $end = CarbonImmutable::now($zone);
        // Only calls from LabelReportCalls::aiSince() on (Jose, 2026-10-13).
        $start = max($end->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay(), LabelReportCalls::aiSince());
        $until = now()->addMinutes((int) $this->option('minutes'));

        $queues = [RingCentralCallLog::NEW_CLIENT_QUEUE, RingCentralCallLog::EXISTING_CLIENT_QUEUE];

        $wanted = array_values(array_filter(
            $calls->forPeriod($start, $end),
            fn (array $call) => $call['recording_id'] !== null
                && $call['answered_by'] !== null
                && in_array($call['queue'], $queues, true),
        ));

        $done = ['lead' => 0, 'whisper' => 0, 'failed' => 0];

        $tenant->run($organization, function () use ($wanted, $calls, $whisper, $until, &$done) {
            $have = CallReportTranscript::query()
                ->whereIn('call_id', array_column($wanted, 'id'))
                ->pluck('call_id')
                ->flip();

            // Rows written before who / when / number / recording were kept
            // get them from the same call log (the Scoring screen lists a
            // person's calls by them).
            $byId = array_column($wanted, null, 'id');
            CallReportTranscript::query()->whereIn('call_id', array_keys($byId))
                ->where(fn ($q) => $q->whereNull('caller_number')->orWhereNull('answered_by'))
                ->get()
                ->each(fn (CallReportTranscript $row) => $row->forceFill($this->who($byId[$row->call_id]))->save());

            // Newest first: today's calls are the ones somebody is looking at.
            usort($wanted, fn ($a, $b) => strcmp((string) $b['started_at'], (string) $a['started_at']));

            foreach ($wanted as $call) {
                if (isset($have[$call['id']]) || now()->greaterThan($until)) {
                    continue;
                }

                [$source, $words] = $this->fromLead($call) ?? $this->fromWhisper($call, $calls, $whisper);

                CallReportTranscript::query()->create([
                    'organization_id' => app(TenantContext::class)->idOrFail(),
                    'call_id' => $call['id'],
                    'transcript' => $words,
                    'source' => $source,
                    ...$this->who($call),
                ]);

                $done[$source]++;
            }
        });

        $this->info("From Local Services: {$done['lead']}, Whisper: {$done['whisper']}, failed: {$done['failed']}.");

        return self::SUCCESS;
    }

    /**
     * Who took the call, when, from which number and its recording: for
     * per-person scoring (Grok AI, 2026-10-13) and a person's calls on the
     * Scoring screen (Jose, 2026-10-14). Named as the report names them.
     *
     * @return array<string, mixed>
     */
    private function who(array $call): array
    {
        return [
            'answered_by' => CallReconciliation::ALIASES[$call['answered_by']] ?? $call['answered_by'],
            'call_started_at' => isset($call['started_at']) ? CarbonImmutable::parse($call['started_at']) : null,
            'caller_number' => $call['from'] ?? null,
            'recording_id' => $call['recording_id'] ?? null,
        ];
    }

    /** @return array{0: string, 1: string}|null */
    private function fromLead(array $call): ?array
    {
        $phone = NumberNames::lastTen($call['from'] ?? null);
        $at = $call['started_at'] ? CarbonImmutable::parse($call['started_at']) : null;

        if ($phone === null || $at === null) {
            return null;
        }

        $match = DB::table('lead_conversations')
            ->join('leads', 'leads.id', '=', 'lead_conversations.lead_id')
            ->where('lead_conversations.organization_id', app(TenantContext::class)->idOrFail())
            ->where('lead_conversations.channel', 'PHONE_CALL')
            ->whereNotNull('lead_conversations.transcript')
            ->where('lead_conversations.transcript', '!=', '')
            ->whereBetween('lead_conversations.occurred_at', [
                $at->subSeconds(self::MATCH_SECONDS)->utc(),
                $at->addSeconds(self::MATCH_SECONDS)->utc(),
            ])
            ->get(['leads.phone', 'lead_conversations.transcript'])
            ->first(fn ($row) => NumberNames::lastTen($row->phone) === $phone);

        return $match === null ? null : ['lead', (string) $match->transcript];
    }

    /** @return array{0: string, 1: ?string} */
    private function fromWhisper(array $call, RingCentralCallLog $calls, CallTranscripts $whisper): array
    {
        if (! $whisper->local()) {
            return ['failed', null];
        }

        try {
            return ['whisper', $whisper->whisperAudio($calls->recording((string) $call['recording_id'])['body'])];
        } catch (Throwable $e) {
            // Recorded as failed so it is not retried every five minutes for
            // ever. The reason is logged, never stored: it can carry a URL.
            report($e);

            return ['failed', null];
        }
    }

    /** Whose report: the one named, or the one organization with Workiz connected. */
    public static function reportOrganization(?string $id): ?Organization
    {
        if ($id !== null) {
            return Organization::query()->find($id);
        }

        $ids = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Workiz->value)
            ->distinct()
            ->pluck('organization_id');

        return $ids->count() === 1 ? Organization::query()->find($ids->first()) : null;
    }
}
