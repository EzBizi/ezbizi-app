<?php

namespace App\Console\Commands;

use App\Models\AiEvalCase;
use App\Support\Ai\GrokCli;
use Illuminate\Console\Command;

/**
 * Replays every pinned case through Grok and checks the fields a person said
 * were right. Run it after changing a prompt; it fails (exit 1) on any miss.
 */
class AiEval extends Command
{
    protected $signature = 'ezbizi:ai-eval {--scope= : only cases of this scope, e.g. LabelCallTranscripts}';

    protected $description = 'Replay pinned AI eval cases and report pass/fail';

    public function handle(): int
    {
        $cases = AiEvalCase::query()->when($this->option('scope'), fn ($q, $s) => $q->where('scope', $s))->orderBy('created_at')->get();
        if ($cases->isEmpty()) {
            $this->info('No pinned cases.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;
        foreach ($cases as $case) {
            try {
                $got = GrokCli::ask($case->prompt, $case->schema, 120);
                $misses = self::misses($case->expected, $got);
            } catch (\RuntimeException $e) {
                $misses = ['error' => $e->getMessage()];
            }
            $failed += $misses === [] ? 0 : 1;
            $rows[] = [$case->id, $case->scope, $misses === [] ? 'pass' : 'FAIL', $misses === [] ? '' : mb_substr(json_encode($misses), 0, 120)];
        }

        $this->table(['case', 'scope', 'result', 'differences'], $rows);
        $this->line(($cases->count() - $failed).'/'.$cases->count().' passed');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Fields of $expected that $got does not match (strings compared case-insensitively). */
    public static function misses(array $expected, array $got): array
    {
        $out = [];
        foreach ($expected as $key => $want) {
            $have = $got[$key] ?? null;
            $same = is_string($want) && is_string($have) ? mb_strtolower(trim($want)) === mb_strtolower(trim($have)) : $want == $have;
            if (! $same) {
                $out[$key] = ['expected' => $want, 'got' => $have];
            }
        }

        return $out;
    }
}
