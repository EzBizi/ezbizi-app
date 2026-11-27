<?php

namespace App\Console\Commands;

use App\Models\CallReportLabel;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\CallTranscripts;
use App\Support\Phone\NumberNames;
use App\Support\Reports\Experimental\PersonNames;
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
 * Every answered, recorded call, on any line (Jose, 2026-10-14: calls on
 * the Angi, Thumbtack and other lines were "not being rated or scored or
 * anything"). Bounded by --minutes, and run after Leads' own
 * transcription by deploy/ezbizi-transcribe.service, never beside it.
 */
class TranscribeReportCalls extends Command
{
    /** @var array<string, PersonNames> organization => its names, for this run */
    private array $names = [];

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

        // Every connected account's calls, each marked with its account so the
        // recording is fetched from the right one (EverDoors, 2026-10-29).
        $wanted = [];
        foreach ($calls->accounts() as $account) {
            $calls->only($account);
            foreach ($calls->forPeriod($start, $end) as $call) {
                if ($call['recording_id'] !== null && $call['answered_by'] !== null) {
                    $wanted[] = $call + ['account' => $account];
                }
            }
        }

        $done = ['lead' => 0, 'whisper' => 0, 'failed' => 0];

        $tenant->run($organization, function () use ($wanted, $calls, $whisper, $until, &$done) {
            $byId = array_column($wanted, null, 'id');

            // A Local Services transcript far too short for the call is another
            // leg's words (measured 2026-10-14: 219 characters for a 6-minute
            // call, read as "no conversation" and never scored). Whisper redoes it.
            $have = CallReportTranscript::query()
                ->whereIn('call_id', array_keys($byId))
                ->get(['call_id', 'source', 'transcript'])
                ->reject(fn ($row) => $row->source === 'lead' && self::tooShort($byId[$row->call_id], $row->transcript))
                ->pluck('call_id')
                ->flip();

            // Rows written before who / when / number / recording were kept
            // get them from the same call log (the Scoring screen lists a
            // person's calls by them).
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

                $calls->only($call['account']);
                $redo = CallReportTranscript::query()->where('call_id', $call['id'])->exists();
                [$source, $words] = ($redo ? null : $this->fromLead($call)) ?? $this->fromWhisper($call, $calls, $whisper);

                if ($redo && $source === 'failed') {
                    continue; // keep the short words rather than none
                }

                CallReportTranscript::query()->updateOrCreate(['call_id' => $call['id']], [
                    'organization_id' => app(TenantContext::class)->idOrFail(),
                    'transcript' => $words,
                    'transcript_labelled' => null,
                    'source' => $source,
                    ...$this->who($call),
                ]);
                // Labelled and scored from the wrong words; asked again from the new ones.
                CallReportLabel::query()->where('call_id', $call['id'])->delete();
                CallReportScore::query()->where('call_id', $call['id'])->delete();

                $done[$source]++;
            }
        });

        $calls->only(null);
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
        // Per organization: one run reads several, and each has its own pairs.
        $names = $this->names[(string) app(TenantContext::class)->get()?->getKey()] ??= PersonNames::forTenant();

        return [
            'answered_by' => $names->canonical($call['answered_by']) ?? $call['answered_by'],
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

        return $match === null || self::tooShort($call, (string) $match->transcript)
            ? null
            : ['lead', (string) $match->transcript];
    }

    /** Under a character a second of a two-minute-plus call: not this call's words. */
    private static function tooShort(array $call, ?string $words): bool
    {
        $talk = (int) ($call['talk_seconds'] ?? 0);

        return $talk >= 120 && strlen((string) $words) < $talk;
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
