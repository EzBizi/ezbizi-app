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
        {--relabel= : ask again for calls holding this label (e.g. other), overwriting it}';

    protected $description = 'Label the Performance report\'s calls with Claude and Grok';

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
        $start = $end->subDays(max(1, (int) $this->option('days')) - 1)->startOfDay();
        $until = now()->addMinutes((int) $this->option('minutes'));

        // The queue decides the question, so it comes from the call log.
        $queues = array_column($calls->forPeriod($start, $end), 'queue', 'id');
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
     * @param  list<string>  $callIds
     */
    private function splitSpeakers(array $callIds, TenantContext $tenant, $organization, $until): int
    {
        if (! GrokCli::configured()) {
            return 0;
        }

        $split = 0;

        $tenant->run($organization, function () use ($callIds, $until, &$split) {
            $rows = CallReportTranscript::query()
                ->whereIn('call_id', $callIds)
                ->whereNull('transcript_labelled')
                ->whereNotNull('transcript')
                ->where('transcript', '!=', '')
                ->latest()
                ->get();

            foreach ($rows as $row) {
                if (now()->greaterThan($until)) {
                    return;
                }

                try {
                    $labelled = TranscriptSpeakers::label((string) $row->transcript);
                } catch (Throwable $e) {
                    report($e);

                    return;
                }

                $row->forceFill(['transcript_labelled' => $labelled ?? $row->transcript])->save();
                $split++;
            }
        });

        return $split;
    }
}
