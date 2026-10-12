<?php

namespace App\Support\Ai;

use Illuminate\Contracts\Process\ProcessResult;
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
    private const CONCURRENCY = 4;

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
    public static function ask(string $prompt, array $schema): array
    {
        $answer = self::askMany([$prompt], $schema)[0];

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
     * ponytail: CONCURRENCY processes at a time; raise it when the plan's rate
     * limit allows, lower it if Grok starts refusing.
     *
     * @param  list<string>  $prompts
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>|RuntimeException> in the prompts' order
     */
    public static function askMany(array $prompts, array $schema): array
    {
        $home = (string) config('services.grok_cli.home');
        $answers = [];

        foreach (array_chunk($prompts, self::CONCURRENCY, true) as $chunk) {
            $works = [];
            foreach ($chunk as $i => $prompt) {
                $works[$i] = storage_path('app/grok-'.bin2hex(random_bytes(6)));
                File::ensureDirectoryExists($works[$i].'/cwd', 0700);
                File::put($works[$i].'/prompt.txt', $prompt);
            }

            try {
                $results = Process::pool(function ($pool) use ($works, $home, $schema) {
                    foreach ($works as $i => $work) {
                        $pool->as((string) $i)->env(['HOME' => $home])->timeout(120)->command([
                            $home.'/.grok/bin/grok',
                            '--prompt-file', $work.'/prompt.txt',
                            '--json-schema', json_encode($schema),
                            '--max-turns', '1',
                            '--disable-web-search',
                            '--no-subagents',
                            '--tools', '',
                            '--cwd', $work.'/cwd',
                        ]);
                    }
                })->start()->wait();
            } finally {
                foreach ($works as $work) {
                    File::deleteDirectory($work);
                }
            }

            foreach (array_keys($works) as $i) {
                $answers[$i] = self::answer($results[(string) $i]);
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
