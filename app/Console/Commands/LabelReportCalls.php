<?php

namespace App\Console\Commands;

use App\Models\CallReportLabel;
use App\Models\CallReportTranscript;
use App\Support\Ai\GrokCli;
use App\Support\Ai\TranscriptSpeakers;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Tagging\CallLabels;
use App\Support\Reports\Tagging\CallTagger;
use App\Support\Reports\Tagging\ClaudeTagger;
use App\Support\Reports\Tagging\GrokTagger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Labels the Performance report's transcribed calls with Claude and Grok, side
 * by side (Jose, 2026-10-06: "build for both, so i can try both and see which
 * one i like more"). Existing-client calls get why they called; new-client
 * calls get why they did not book (CallLabels).
 *
 * OFF until REPORT_CALL_TAGGING is on: it sends transcripts to Anthropic and
 * xAI, and the privacy page has to say so first. Each provider runs only
 * with its own key. Runs after transcription in deploy/ezbizi-transcribe.service.
 */
class LabelReportCalls extends Command
{
    protected $signature = 'ezbizi:label-report-calls
        {--days=2 : days back to look, including today}
        {--minutes=2 : how long a run may take}
        {--organization= : whose report; defaults to the one organization with Workiz connected}
        {--relabel= : ask again for calls holding this label (e.g. other), overwriting it}
        {--split-only : only divide the last 7 days of transcripts by speaker, several at a time}';

    protected $description = 'Label the Performance report\'s calls with Claude and Grok';

    /**
     * Nothing before this day goes to AI (Jose, 2026-10-13: "just new stuff
     * from now on"). ponytail: GrokCli::since() reads the same setting once
     * Grok AI's 7f3e81ba is on main; switch to it then.
     */
    public static function aiSince(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('services.grok_cli.since', '2026-10-13'), (string) config('app.timezone'))->startOfDay();
    }

    public function handle(RingCentralCallLog $calls, TenantContext $tenant): int
    {
        if (! config('experimental_reports.tagging.enabled')) {
            $this->info('Call labelling is switched off (REPORT_CALL_TAGGING).');

            return self::SUCCESS;
        }

        /** @var list<CallTagger> $taggers */
        $taggers = array_values(array_filter(
            [app(ClaudeTagger::class), app(GrokTagger::class)],
            fn (CallTagger $t) => $t->configured(),
        ));

        if ($taggers === [] || ! $calls->configured()) {
            $this->info('No AI key or no RingCentral; nothing to label.');

            return self::SUCCESS;
        }

        $organization = TranscribeReportCalls::reportOrganization($this->option('organization'));

        if ($organization === null) {
            $this->warn('Which organization? Pass --organization.');

            return self::FAILURE;
        }

        $zone = (string) config('app.timezone');
        $end = CarbonImmutable::now($zone);
        $start = max($end->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay(), self::aiSince());
        $until = now()->addMinutes((int) $this->option('minutes'));

        // Its own run (every 5 minutes): splitting after labelling got the
        // seconds labelling left, about one call a run, and 1,728 piled up
        // that coaching cannot score (Grok AI, measured 2026-10-13).
        if ($this->option('split-only')) {
            $split = $this->splitSpeakers(null, $tenant, $organization, $until);
            $this->info("Split by speaker: {$split}");

            return self::SUCCESS;
        }

        // The queue decides the question, so it comes from the call log.
        $queues = [];
        foreach ($calls->accounts() as $account) {
            $calls->only($account);
            $queues += array_column($calls->forPeriod($start, $end), 'queue', 'id');
        }
        $calls->only(null);
        $done = [];

        // A label set that grew (2026-10-12) leaves old "other" rows behind;
        // --relabel=other asks those again, in the same time budget.
        $relabel = $this->option('relabel');

        $tenant->run($organization, function () use ($queues, $taggers, $until, $relabel, &$done) {
            $transcripts = CallReportTranscript::query()
                ->whereIn('call_id', array_keys($queues))
                ->whereNotNull('transcript')
                ->where('transcript', '!=', '')
                ->latest()
                ->get(['call_id', 'transcript']);

            foreach ($taggers as $tagger) {
                $have = CallReportLabel::query()
                    ->where('provider', $tagger->name())
                    ->whereIn('call_id', $transcripts->pluck('call_id'))
                    ->when($relabel !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('label')->orWhere('label', '!=', $relabel)))
                    ->pluck('call_id')
                    ->flip();

                foreach ($transcripts as $row) {
                    $ask = CallLabels::for($queues[$row->call_id] ?? null);

                    if ($ask === null || isset($have[$row->call_id]) || now()->greaterThan($until)) {
                        continue;
                    }

                    try {
                        $label = $tagger->tag((string) $row->transcript, $ask['question'], $ask['labels']);
                    } catch (Throwable $e) {
                        // Left unlabelled and tried again next run; logged, never stored.
                        report($e);

                        continue;
                    }

                    CallReportLabel::query()->updateOrCreate([
                        'organization_id' => app(TenantContext::class)->idOrFail(),
                        'call_id' => $row->call_id,
                        'provider' => $tagger->name(),
                    ], ['label' => $label]);

                    $done[$tagger->name()] = ($done[$tagger->name()] ?? 0) + 1;
                }
            }
        });

        $split = $this->splitSpeakers(array_keys($queues), $tenant, $organization, $until);

        $this->info('Labelled: '.json_encode($done ?: (object) []).", split by speaker: {$split}");

        return self::SUCCESS;
    }

    /**
     * The transcript divided into IVR menu / Dispatcher / Customer, with the
     * prompt Leads uses (Jose, 2026-10-12, on Leads: "have grok divide them
     * properly ... so it's easier to read them"), in what time this run has
     * left. An answer that lost the words keeps the raw text; a Grok failure
     * is reported and left for the next run.
     *
     * Several side by side (TranscriptSpeakers::readMany). Null $callIds is
     * every transcript of the last 7 days, the window coaching scores.
     *
     * @param  list<string>|null  $callIds
     */
    private function splitSpeakers(?array $callIds, TenantContext $tenant, $organization, $until): int
    {
        if (! GrokCli::configured()) {
            return 0;
        }

        $split = 0;

        $tenant->run($organization, function () use ($callIds, $until, &$split) {
            $rows = CallReportTranscript::query()
                ->when($callIds !== null, fn ($q) => $q->whereIn('call_id', $callIds))
                ->when($callIds === null, fn ($q) => $q->where('created_at', '>=', max(now()->subDays(7), self::aiSince())))
                ->whereNull('transcript_labelled')
                ->whereNotNull('transcript')
                ->where('transcript', '!=', '')
                ->latest()
                ->get();

            foreach ($rows->chunk(max(1, (int) config('services.grok_cli.concurrency', 4))) as $batch) {
                if (now()->greaterThan($until)) {
                    return;
                }

                $reads = TranscriptSpeakers::readMany($batch->map(fn ($row) => (string) $row->transcript)->values()->all());

                foreach ($batch->values() as $n => $row) {
                    if ($reads[$n] instanceof Throwable) {
                        report($reads[$n]);   // left for the next run

                        continue;
                    }

                    $row->forceFill(['transcript_labelled' => $reads[$n]['labelled'] ?? $row->transcript])->save();
                    $split++;
                }
            }
        });

        return $split;
    }
}
