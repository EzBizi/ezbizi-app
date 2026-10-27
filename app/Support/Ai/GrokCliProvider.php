<?php

namespace App\Support\Ai;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Grok through the Grok Build CLI on the box (GrokCli's provider since
 * 2026-10-27). Headless and with nothing to act with: no tools (but
 * read_file beside a photo), no web, no subagents, in an empty directory.
 * The prompt goes in a file, never on the command line, where `ps` shows it.
 */
final class GrokCliProvider implements AiProvider
{
    public function name(): string
    {
        return 'grok';
    }

    public function configured(): bool
    {
        return filled(config('services.grok_cli.home'));
    }

    public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
    {
        $home = (string) config('services.grok_cli.home');
        $answers = [];

        foreach (array_chunk($prompts, max(1, (int) config('services.grok_cli.concurrency', 4)), true) as $chunk) {
            $works = [];
            foreach ($chunk as $i => $prompt) {
                $works[$i] = storage_path('app/grok-'.bin2hex(random_bytes(6)));
                File::ensureDirectoryExists($works[$i].'/cwd', 0700);
                File::put($works[$i].'/prompt.txt', $prompt);
                foreach ($files as $name => $bytes) {
                    File::put($works[$i].'/cwd/'.basename((string) $name), $bytes);
                }
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
                        // A photo (a bill) is read by the CLI's read_file tool from a
                        // folder holding only that file (measured on prod 2026-10-16:
                        // no other way in; `read` gave no structured output).
                        '--max-turns', $files === [] ? '1' : '3',
                        '--disable-web-search',
                        '--no-subagents',
                        '--tools', $files === [] ? '' : 'read_file',
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
                        GrokCli::failed('timed out after '.$timeout.'s');
                        $results[$i] = new RuntimeException('The Grok CLI timed out after '.$timeout.'s');
                    }
                }
            } finally {
                foreach ($works as $work) {
                    File::deleteDirectory($work);
                }
            }

            foreach ($results as $i => $result) {
                $answers[$i] = $result instanceof RuntimeException ? $result : $this->answer($result);
            }
        }

        ksort($answers);

        return array_values($answers);
    }

    /** @return array<string, mixed>|RuntimeException */
    private function answer(ProcessResult $result): array|RuntimeException
    {
        if (! $result->successful()) {
            // The CLI's own error, cut short; never the prompt.
            $why = 'exit '.$result->exitCode().': '.mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300);
            GrokCli::failed($why);

            return new RuntimeException('The Grok CLI failed (exit '.$result->exitCode().'): '
                .mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300));
        }

        // `structuredOutput`, measured on the box (grok 1.0.50).
        $answer = json_decode($result->output(), true)['structuredOutput'] ?? null;

        if (! is_array($answer)) {
            GrokCli::failed('no structured output');

            return new RuntimeException('The Grok CLI answered without structured output: '
                .mb_substr(trim($result->output()), 0, 200));
        }

        Cache::forever('grok.last_ok', now()->toIso8601String());

        return $answer;
    }
}
