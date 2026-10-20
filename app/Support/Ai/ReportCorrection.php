<?php

namespace App\Support\Ai;

use App\Models\Franchise;
use App\Models\User;
use App\Models\WhatsAppReportBatch;
use App\Support\Reports\Drafts\ReportChanges;
use App\Support\Reports\Texts\ReportGoals;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * A free-text correction to the open report draft over WhatsApp ("leave out
 * job 4512 and put CT first"), A1 Leads takeover 2026-10-20.
 *
 * Grok only maps the words onto ReportChanges::KEYS; a key it invents, a
 * branch or goal it makes up, is dropped here. The plan is repeated back in
 * words WE write from the validated changes, never Grok's own summary, and
 * nothing runs until the person answers YES. ReportChanges::apply() then
 * re-checks reports.correct and that the draft is still open.
 */
final class ReportCorrection
{
    public const PERMISSION = 'reports.correct';

    public const SETTING = 'ai_assistance.report_corrections';

    private const TTL_MINUTES = 10;

    /** The draft APPROVE would act on, if any. */
    public static function openBatch(): ?WhatsAppReportBatch
    {
        return WhatsAppReportBatch::query()->whereNull('approved_at')->latest('starts_on')->first();
    }

    /** Cheap gate before Grok: words a report correction uses. */
    public static function looksLikeOne(string $text): bool
    {
        return preg_match('/\b(report|exclude|leave out|take out|remove|include|put back|order|first|last|goal|target|job\s*#?\d+)\b/iu', $text) === 1;
    }

    /**
     * @return array{changes: list<array{key: string, params: array}>, lines: list<string>, question: ?string}|null
     *                                                                                                              null: not a correction
     */
    public static function plan(string $text, int $timeout = 60): ?array
    {
        $branches = Franchise::query()->orderBy('name')->get(['id', 'name']);
        $keys = collect(ReportChanges::KEYS)->map(fn ($k, $key) => "- {$key}: {$k[0]}. Params: ".json_encode($k[1]))->implode("\n");
        $goals = collect(ReportGoals::KEYS)->map(fn ($g, $key) => "{$key} ({$g[0]})")->implode(', ');

        $p = GrokCli::ask(
            'You have NO tools and cannot read code or files. A manager replied on WhatsApp to the draft of '
            .'their business report. Decide whether they are asking to CORRECT the draft, and if so map '
            ."their words onto these changes ONLY:\n{$keys}\n\nBRANCHES (id: name):\n"
            .$branches->map(fn ($b) => "{$b->id}: {$b->name}")->implode("\n")
            ."\n\nGOAL KEYS: {$goals}\n\n"
            .'- is_correction: false if they are not asking to change the report.'
            ."\n- changes: each {key, params} where params is a JSON object string using the branch ids above."
            ."\n- question: ONE short question if part of the request does not fit these changes or is unclear, else empty."
            ."\n\nREPLY: ".$text,
            [
                'type' => 'object',
                'properties' => [
                    'is_correction' => ['type' => 'boolean'],
                    'changes' => ['type' => 'array', 'items' => [
                        'type' => 'object',
                        'properties' => ['key' => ['type' => 'string'], 'params' => ['type' => 'string']],
                        'required' => ['key', 'params'],
                        'additionalProperties' => false,
                    ]],
                    'question' => ['type' => 'string'],
                ],
                'required' => ['is_correction', 'changes', 'question'],
                'additionalProperties' => false,
            ],
            $timeout,
        );

        if (($p['is_correction'] ?? false) !== true) {
            return null;
        }

        $names = $branches->pluck('name', 'id')->map(fn ($n) => (string) $n);
        $changes = [];
        $lines = [];
        foreach ((array) ($p['changes'] ?? []) as $c) {
            $key = (string) ($c['key'] ?? '');
            $params = (array) (json_decode((string) ($c['params'] ?? ''), true) ?: []);
            $line = self::describe($key, $params, $names->all());
            if ($line !== null) {
                $changes[] = ['key' => $key, 'params' => $params];
                $lines[] = $line;
            }
        }
        $question = trim((string) ($p['question'] ?? '')) ?: null;

        return $changes === [] && $question === null ? null : ['changes' => $changes, 'lines' => $lines, 'question' => $question];
    }

    /**
     * The change in our words, or null when it is not a valid change: an
     * unknown key, a branch or goal that does not exist, an empty job list.
     *
     * @param  array<string, string>  $branches  id => name
     */
    public static function describe(string $key, array $params, array $branches): ?string
    {
        $jobs = array_values(array_filter(array_map(fn ($j) => preg_replace('/\D/', '', (string) $j), (array) ($params['jobs'] ?? []))));

        return match ($key) {
            'report.exclude_jobs' => $jobs === [] ? null : "\u{2796} Leave out job".(count($jobs) > 1 ? 's' : '').' *'.implode(', ', $jobs).'*',
            'report.include_jobs' => $jobs === [] ? null : "\u{2795} Put back job".(count($jobs) > 1 ? 's' : '').' *'.implode(', ', $jobs).'*',
            'report.company_order' => (function () use ($params, $branches) {
                $ids = array_map('strval', (array) ($params['branches'] ?? []));

                return $ids === [] || array_diff($ids, array_keys($branches)) !== []
                    ? null : "\u{1F522} Company order: *".implode(' → ', array_map(fn ($id) => $branches[$id], $ids)).'*';
            })(),
            'report.goal' => (function () use ($params, $branches) {
                $goal = (string) ($params['key'] ?? '');
                $branch = $params['branch'] ?? null;
                $value = $params['value'] ?? null;
                if (! isset(ReportGoals::KEYS[$goal]) || ($branch !== null && ! isset($branches[(string) $branch])) || ($value !== null && ! is_numeric($value))) {
                    return null;
                }

                return "\u{1F3AF} ".ReportGoals::KEYS[$goal][0].' goal: *'.($value === null ? 'cleared' : $value).'* for '
                    .($branch === null ? 'every company' : '*'.$branches[(string) $branch].'*');
            })(),
            default => null,
        };
    }

    public static function hold(User $viewer, array $plan): void
    {
        Cache::put(self::key($viewer), $plan, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function pending(User $viewer): ?array
    {
        return Cache::get(self::key($viewer));
    }

    public static function drop(User $viewer): void
    {
        Cache::forget(self::key($viewer));
    }

    /**
     * Applies the held changes to the draft that is open NOW, one by one; a
     * refusal stops there and says why.
     *
     * @return array{ok: bool, message: string}
     */
    public static function run(User $viewer, array $plan): array
    {
        self::drop($viewer);
        $batch = self::openBatch();
        if ($batch === null) {
            return ['ok' => false, 'message' => 'There is no report draft waiting for approval.'];
        }

        foreach ($plan['changes'] as $i => $c) {
            try {
                $batch = app(ReportChanges::class)->apply($batch, $c['key'], $c['params'], $viewer);
            } catch (ValidationException $e) {
                return ['ok' => false, 'message' => ($i > 0 ? 'The first '.$i.' change'.($i > 1 ? 's were' : ' was').' made. ' : '')
                    .($e->errors()['change'][0] ?? 'That change was refused.')];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    private static function key(User $viewer): string
    {
        return 'report-correction:'.$viewer->getKey();
    }
}
