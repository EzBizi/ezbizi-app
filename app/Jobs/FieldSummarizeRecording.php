<?php

namespace App\Jobs;

use App\Models\FieldRecording;
use App\Models\Organization;
use App\Support\Ai\GrokCli;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A job summary from a recording's transcript (Grok), kept on the recording. Never sent to a customer. */
class FieldSummarizeRecording implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'done' => ['type' => 'string'],
            'found' => ['type' => 'string'],
            'recommended' => ['type' => 'string'],
            'customer_asked' => ['type' => 'string'],
        ],
        'required' => ['summary', 'done', 'found', 'recommended', 'customer_asked'],
    ];

    public function __construct(public string $recordingId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(TenantContext $tenant): void
    {
        $recording = FieldRecording::query()->withoutGlobalScopes()->find($this->recordingId);
        if ($recording === null || $recording->summarized_at !== null || ! GrokCli::configured()) {
            return;
        }

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($recording->organization_id), function () use ($recording) {
            $lines = $recording->segments()->orderBy('started_at')->get()
                ->map(fn ($s) => $s->started_at->format('H:i:s').' '.$s->text)->implode("\n");
            if ($lines === '') {
                $recording->forceFill(['summarized_at' => now()])->save(); // nothing was said

                return;
            }
            $job = $recording->job;
            $prompt = "A field technician recorded this visit for safety and quality. Transcript, with clock time:\n\n{$lines}\n\n"
                .'Job: '.($job ? "{$job->number} {$job->title}" : 'not linked').'. '
                .'Summarise for the office in plain words: what was done, what was found, what the technician '
                .'recommended, and anything the customer asked for. Leave a field empty if the transcript does not say.';
            $answer = GrokCli::about($job ?? $recording, fn () => GrokCli::ask($prompt, self::SCHEMA, 60));

            $recording->forceFill(['summary' => $answer, 'summarized_at' => now()])->save();
        });
    }
}
