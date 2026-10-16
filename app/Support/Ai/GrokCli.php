<?php

namespace App\Support\Ai;

use App\Models\AiTrace;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Eloquent\Model;
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
    /**
     * How every Grok reply that goes out on WhatsApp is laid out (Jose,
     * 2026-10-13: "use emojis, line breaks ... better ui-ux experience").
     * WhatsApp renders *bold* and _italic_, not Markdown headings or tables.
     */
    public const WHATSAPP_STYLE = 'FORMAT FOR WHATSAPP: start with a short *bold* title line with one fitting emoji. '
        ."Then one fact per line, each line starting with a fitting emoji (for example \u{1F4B0} money, "
        ."\u{1F4C5} dates, \u{1F527} jobs, \u{1F464} people, \u{2705} done, \u{274C} canceled, \u{1F4CD} places, "
        ."\u{1F4DE} phone). Put a blank line between groups. Use *bold* for names and key numbers. No "
        .'Markdown headings (#), no tables, no ** double asterisks. Keep it short.';

    /** A long call's speaker split can take minutes; 120 s timed out on prod. */
    private const TIMEOUT = 300;

    /** The oldest call or lead AI reads (GROK_AI_SINCE): nothing before it is worth a Grok call. */
    public static function since(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('services.grok_cli.since', '2026-10-13'))->startOfDay();
    }

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
    public static function ask(string $prompt, array $schema, int $timeout = self::TIMEOUT, array $files = []): array
    {
        $answer = self::askMany([$prompt], $schema, $timeout, $files)[0];

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
     * @param  array<string, string>  $files  name => bytes put beside the question for it to read (a photo), read-only
     * @param  array<string, mixed>  $schema
     * @return list<array<string, mixed>|RuntimeException> in the prompts' order
     */
    public static function askMany(array $prompts, array $schema, int $timeout = self::TIMEOUT, array $files = []): array
    {
        $home = (string) config('services.grok_cli.home');
        $answers = [];

        foreach (array_chunk($prompts, max(1, (int) config('services.grok_cli.concurrency', 4)), true) as $chunk) {
            $started = microtime(true);
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
                self::trace($chunk[$i], $schema, $answers[$i], (int) round((microtime(true) - $started) * 1000), $i);
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
    /**
     * Every call kept a month in ai_traces (A1 Leads takeover: AI trace and
     * "pin as test case"). Never fails the caller: a trace that cannot be
     * written is lost, the answer is not.
     */
    private static function trace(string $prompt, array $schema, array|RuntimeException $answer, int $ms, int $index): void
    {
        try {
            $subject = is_array(self::$about) ? (self::$about[$index] ?? null) : self::$about;
            $caller = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 8))
                ->first(fn (array $f) => isset($f['class']) && $f['class'] !== self::class);
            AiTrace::create([
                'organization_id' => app(TenantContext::class)->id(),
                'scope' => mb_substr(class_basename($caller['class'] ?? 'unknown'), 0, 120),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject === null ? null : (string) $subject->getKey(),
                'prompt' => $prompt,
                'schema' => $schema,
                'response' => $answer instanceof RuntimeException ? null : $answer,
                'error' => $answer instanceof RuntimeException ? mb_substr($answer->getMessage(), 0, 400) : null,
                'ms' => $ms,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @var Model|list<?Model>|null */
    private static $about = null;

    /**
     * Runs $ask with its traces tied to whose data the prompts carry: one model
     * for every prompt, or a list in askMany's prompt order.
     *
     * @template T
     *
     * @param  Model|list<?Model>|null  $subject
     * @param  \Closure(): T  $ask
     * @return T
     */
    public static function about(Model|array|null $subject, \Closure $ask): mixed
    {
        [$before, self::$about] = [self::$about, $subject];
        try {
            return $ask();
        } finally {
            self::$about = $before;
        }
    }

    private static function failed(string $why): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => $why]);
    }
}
