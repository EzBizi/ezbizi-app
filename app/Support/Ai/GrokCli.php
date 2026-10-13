<?php

namespace App\Support\Ai;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Grok through the Grok Build CLI, signed in to Jose's own Grok subscription
 * on the box (Jose, 2026-10-09: "Yes set it up", told of xAI's consumer
 * terms; data retention turned off by him the same day). Shared by every
 * module that asks Grok something, so there is one invocation to keep safe.
 *
 * Headless and with nothing to act with: no tools, no web, no subagents, one
 * turn, in an empty directory. The prompt goes in a file, never on the
 * command line, where `ps` would show customer text.
 *
 * Whoever sends text through this needs the privacy page to say so first.
 */
final class GrokCli
{
    /** A long call's speaker split can take minutes; 120 s timed out on prod. */
    private const TIMEOUT = 300;


    public static function configured(): bool
    {
        return filled(config('services.grok_cli.home'));
    }

    /**
     * @param  array<string, mixed>  $schema  JSON Schema the answer must match
     * @return array<string, mixed> the structured answer
     *
     * @throws RuntimeException when the CLI fails (an expired sign-in included)
     *                          or answers without the structured output: loud,
     *                          never an empty answer
     */
    public static function ask(string $prompt, array $schema, int $timeout = self::TIMEOUT): array
    {
        $answer = self::askMany([$prompt], $schema, $timeout)[0];

        if ($answer instanceof RuntimeException) {
            throw $answer;
        }

        return $answer;
    }

    /**
     * Several questions at once, one CLI process each, run side by side (Jose,
     * 2026-10-12, AI item 4: each answer takes about 8 s and callers asked one
     * at a time). One process per question keeps every answer tied to its own
     * question and a failure to its own row.
     *
     * GROK_CLI_CONCURRENCY processes at a time (default 4); set it to 1 if
     * Grok starts refusing on the plan's rate limit.
     *
     * @param  list<string>  $prompts
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>|RuntimeException> in the prompts' order
     */
    public static function askMany(array $prompts, array $schema, int $timeout = self::TIMEOUT): array
    {
        $home = (string) config('services.grok_cli.home');
        $answers = [];

        foreach (array_chunk($prompts, max(1, (int) config('services.grok_cli.concurrency', 4)), true) as $chunk) {
            $works = [];
            foreach ($chunk as $i => $prompt) {
                $works[$i] = storage_path('app/grok-'.bin2hex(random_bytes(6)));
                File::ensureDirectoryExists($works[$i].'/cwd', 0700);
                File::put($works[$i].'/prompt.txt', $prompt);
            }

            try {
                // Started side by side, waited on one by one, so a call that
                // runs out of time fails alone (prod 2026-10-12: one long
                // transcript's timeout stopped every run behind it).
                $running = [];
                foreach ($works as $i => $work) {
                    $running[$i] = Process::env(['HOME' => $home])->timeout($timeout)->start([
                        $home.'/.grok/bin/grok',
                        '--prompt-file', $work.'/prompt.txt',
                        '--json-schema', json_encode($schema),
                        '--max-turns', '1',
                        '--disable-web-search',
                        '--no-subagents',
                        '--tools', '',
                        '--cwd', $work.'/cwd',
                        // Measured on prod 2026-10-13, one 2.3k-char call:
                        // default 215 s, low 61 s, same speakers and summary.
                        '--reasoning-effort', (string) config('services.grok_cli.effort', 'low'),
                    ]);
                }
                $results = [];
                foreach ($running as $i => $process) {
                    try {
                        $results[$i] = $process->wait();
                    } catch (ProcessTimedOutException) {
                        self::failed('timed out after '.$timeout.'s');
                        $results[$i] = new RuntimeException('The Grok CLI timed out after '.$timeout.'s');
                    }
                }
            } finally {
                foreach ($works as $work) {
                    File::deleteDirectory($work);
                }
            }

            foreach ($results as $i => $result) {
                $answers[$i] = $result instanceof RuntimeException ? $result : self::answer($result);
            }
        }

        ksort($answers);

        return array_values($answers);
    }

    /** @return array<string, mixed>|RuntimeException */
    private static function answer(ProcessResult $result): array|RuntimeException
    {
        if (! $result->successful()) {
            // The CLI's own error, cut short; never the prompt.
            $why = 'exit '.$result->exitCode().': '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300);
            self::failed($why);

            return new RuntimeException('The Grok CLI failed (exit '.$result->exitCode().'): '
                .mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300));
        }

        // `structuredOutput`, measured on the box (grok 1.0.50).
        $answer = json_decode($result->output(), true)['structuredOutput'] ?? null;

        if (! is_array($answer)) {
            self::failed('no structured output');

            return new RuntimeException('The Grok CLI answered without structured output: '
                .mb_substr(trim($result->output()), 0, 200));
        }

        Cache::forever('grok.last_ok', now()->toIso8601String());

        return $answer;
    }

    /** Read by ezbizi:check-grok, which alerts when failures outlast successes. */
    private static function failed(string $why): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => $why]);
    }
}
