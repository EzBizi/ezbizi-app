<?php

namespace App\Console\Commands;

use App\Models\AiEvalCase;
use App\Models\AiTrace;
use Illuminate\Console\Command;

/**
 * Pins a traced Grok call as a test case with the RIGHT answer, which a
 * person gives: copying the AI's own answer as "expected" only tests that it
 * still says what it said (a1leads' pin did that, and never replayed).
 */
class AiPin extends Command
{
    protected $signature = 'ezbizi:ai-pin {trace : ai_traces id} {expected : JSON, the fields that must match, e.g. {"label":"booked"}} {--notes=}';

    protected $description = 'Pin a Grok call as an eval case with the correct answer';

    public function handle(): int
    {
        $trace = AiTrace::findOrFail($this->argument('trace'));
        $expected = json_decode((string) $this->argument('expected'), true);
        if (! is_array($expected) || $expected === []) {
            $this->error('expected must be a JSON object with at least one field.');

            return self::FAILURE;
        }

        $case = AiEvalCase::create([
            'organization_id' => $trace->organization_id, 'scope' => $trace->scope, 'subject_type' => $trace->subject_type, 'subject_id' => $trace->subject_id, 'prompt' => $trace->prompt,
            'schema' => $trace->schema, 'expected' => $expected, 'notes' => $this->option('notes'), 'trace_id' => $trace->id,
        ]);
        $this->info("Pinned {$case->id} ({$trace->scope}).");

        return self::SUCCESS;
    }
}
