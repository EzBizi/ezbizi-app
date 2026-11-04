<?php

namespace App\Support\Ai;

use App\Models\AiTrace;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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
    // Less rigid since 2026-11-03 (Jose: "it should just answer, like you would
    // like an AI agent would"): a direct answer first, the layout only when it helps.
    public const WHATSAPP_STYLE = 'FORMAT FOR WHATSAPP: answer like a helpful colleague texting back: the direct '
        .'answer first, in plain words. For a list or several figures, one per line with a fitting emoji '
        ."(for example \u{1F4B0} money, \u{1F4C5} dates, \u{1F527} jobs, \u{1F464} people, \u{1F4CD} places). "
        .'Use *bold* for names and key numbers. No Markdown headings (#), no tables, no ** double asterisks. Keep it short.';

    /** A long call's speaker split can take minutes; 120 s timed out on prod. */
    private const TIMEOUT = 300;

    /** The oldest call or lead AI reads (GROK_AI_SINCE): nothing before it is worth a Grok call. */
    public static function since(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('services.grok_cli.since', '2026-10-13'))->startOfDay();
    }

    /** Some AI is set up (GrokCli::provider()). */
    public static function configured(): bool
    {
        return self::provider() !== null;
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
        $provider = self::provider();

        // No AI configured for this install: every question gets the same calm
        // failure its caller already handles, never a crash (Jose, 2026-10-27:
        // every integration is a removable add-on).
        if ($provider === null) {
            return array_map(fn () => new AiUnavailable('No AI is configured.'), array_values($prompts));
        }

        $started = microtime(true);
        $answers = $provider->askMany(array_values($prompts), $schema, $timeout, $files);

        // An empty answer is asked once more (prod 2026-10-26..11-02: 13 of
        // 17 failures in 11,746 calls were "without structured output" with
        // empty text - a blip, not a refusal). Timeouts are not retried.
        $empty = array_keys(array_filter($answers, fn ($a) => $a instanceof RuntimeException && str_contains($a->getMessage(), 'without structured output')));
        if ($empty !== []) {
            $again = $provider->askMany(array_values(array_intersect_key(array_values($prompts), array_flip($empty))), $schema, $timeout, $files);
            foreach ($empty as $n => $i) {
                $answers[$i] = $again[$n];
            }
        }
        $ms = (int) round((microtime(true) - $started) * 1000);

        foreach (array_values($prompts) as $i => $prompt) {
            self::trace($prompt, $schema, $answers[$i], $ms, $i);
        }

        return $answers;
    }

    /**
     * The AI behind every caller: AI_PROVIDER grok or claude; "auto" (the
     * default) is Grok when the CLI is set up, else Claude when its key is.
     * Null when neither is: GrokCli::configured() is false and askMany()
     * answers AiUnavailable.
     */
    public static function provider(): ?AiProvider
    {
        $grok = app(GrokCliProvider::class);
        $claude = app(ClaudeProvider::class);

        return match ((string) config('services.ai.provider', 'auto')) {
            'grok' => $grok->configured() ? $grok : null,
            'claude' => $claude->configured() ? $claude : null,
            default => $grok->configured() ? $grok : ($claude->configured() ? $claude : null),
        };
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

    public static function failed(string $why): void
    {
        Cache::forever('grok.last_failure', ['at' => now()->toIso8601String(), 'why' => $why]);
    }
}
