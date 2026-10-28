<?php

namespace App\Support\Jobs;

use App\Models\Job;
use Illuminate\Database\Eloquent\Builder;

/**
 * Project Management's stages, worked out from a job's status, whether it is
 * scheduled and whether money is due (Jose, 2026-10-22: "as you recommend").
 * The organization maps them in `jobs.pm_stages`; nothing here names a status.
 *
 * Each stage's rule stands alone: a job meeting two rules is in both, so the
 * mapping keeps them apart. ponytail: no precedence order; add one if a
 * mapping ever needs "the first that matches".
 */
final class ProjectStages
{
    public const KEYS = ['in_progress', 'waiting_review', 'ready_to_schedule', 'scheduled', 'done_waiting_payment', 'completed', 'cancelled'];

    /** Not started: paid, but the work has not begun (the 10-day warning). */
    public const NOT_STARTED = ['waiting_review', 'ready_to_schedule', 'scheduled'];

    /** Open work, whose totals are the pipeline. */
    public const OPEN = ['in_progress', 'waiting_review', 'ready_to_schedule', 'scheduled'];

    /**
     * Each stage's rules, in order. A stage may have several, any of which
     * puts a job in it (Jose, 2026-10-28: done pending approval whatever its
     * money, Done only while money is due).
     *
     * @param  mixed  $setting  jobs.pm_stages: [{key, statuses[], scheduled?: bool|null, due?: bool|null}]
     * @return array<string, list<array{statuses: list<string>, scheduled: ?bool, due: ?bool}>>
     */
    public static function rules(mixed $setting): array
    {
        $out = [];
        foreach ((array) $setting as $r) {
            if (is_array($r) && in_array($r['key'] ?? null, self::KEYS, true) && ($r['statuses'] ?? []) !== []) {
                $out[$r['key']][] = [
                    'statuses' => array_values(array_map('strval', (array) $r['statuses'])),
                    'scheduled' => isset($r['scheduled']) ? (bool) $r['scheduled'] : null,
                    'due' => isset($r['due']) ? (bool) $r['due'] : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Each stage's rule for these branches, read where each branch's settings
     * resolve (branch, then company, then organization: Jose, 2026-10-22, "it's
     * an ezbizi system ... needs to respect organization - company - franchise
     * levels"). Branches sharing a rule share a group.
     *
     * @param  iterable<\App\Models\Franchise>  $franchises
     * @return array<string, list<array{ids: list<string>, rule: array}>>
     */
    public static function forFranchises(iterable $franchises, \App\Support\Settings\SettingsResolver $settings): array
    {
        $groups = [];
        foreach ($franchises as $f) {
            foreach (self::rules($settings->get('jobs.pm_stages', $f)) as $key => $rules) {
                foreach ($rules as $rule) {
                    $sig = $key.json_encode($rule);
                    $groups[$key][$sig] ??= ['ids' => [], 'rule' => $rule];
                    $groups[$key][$sig]['ids'][] = $f->getKey();
                }
            }
        }

        return array_map('array_values', $groups);
    }

    /** Narrow a job query to one stage, each branch by its own rule; an unmapped stage holds nothing. */
    public static function scope(Builder $q, ?array $groups): Builder
    {
        if (empty($groups)) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($any) use ($groups) {
            foreach ($groups as $g) {
                $rule = $g['rule'];
                $any->orWhere(fn ($one) => $one->whereIn('franchise_id', $g['ids'])->whereIn('status', $rule['statuses'])
                    ->when($rule['scheduled'] !== null, fn ($q) => $rule['scheduled'] ? $q->whereNotNull('scheduled_for') : $q->whereNull('scheduled_for'))
                    ->when($rule['due'] !== null, fn ($q) => $rule['due'] ? JobMoney::due($q) : $q->whereNotIn('id', JobMoney::due(Job::query())->select('id'))));
            }
        });
    }

    /** In any of these stages (each by its own rule); every mapped stage when none are named. */
    public static function anyOf(Builder $q, array $rules, ?array $keys = null): Builder
    {
        $keys = array_values(array_filter($keys ?? array_keys($rules), fn ($k) => isset($rules[$k])));
        if ($keys === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(fn ($any) => array_map(fn ($k) => $any->orWhere(fn ($one) => self::scope($one, $rules[$k])), $keys));
    }

    /**
     * Only the jobs Project Management is about, each branch by its own place's
     * jobs.pm_scope_statuses (empty: any) and jobs.pm_partly_paid_only (Shoval,
     * 2026-10-22: "Only open jobs ... and only ones that are partly paid deposits").
     *
     * @param  iterable<\App\Models\Franchise>  $franchises
     */
    public static function inScope(Builder $q, iterable $franchises, \App\Support\Settings\SettingsResolver $settings): Builder
    {
        $groups = [];
        foreach ($franchises as $f) {
            $statuses = array_values(array_map('strval', (array) $settings->get('jobs.pm_scope_statuses', $f)));
            $partly = (bool) $settings->get('jobs.pm_partly_paid_only', $f);
            $sig = json_encode([$statuses, $partly]);
            $groups[$sig] ??= ['ids' => [], 'statuses' => $statuses, 'partly' => $partly];
            $groups[$sig]['ids'][] = $f->getKey();
        }
        if ($groups === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($any) use ($groups) {
            foreach ($groups as $g) {
                $any->orWhere(fn ($one) => $one->whereIn('franchise_id', $g['ids'])
                    ->when($g['statuses'] !== [], fn ($q) => $q->whereIn('status', $g['statuses']))
                    ->when($g['partly'], fn ($q) => JobMoney::due($q)->whereIn('id', JobMoney::paidJobIds())));
            }
        });
    }

    /** A tag or the job type, any case, is one of these words (redo, dispute: the organization's own words). */
    public static function anyWord(Builder $q, array $words): Builder
    {
        $words = array_values(array_filter(array_map(fn ($w) => mb_strtolower(trim((string) $w)), $words)));
        if ($words === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($words) {
            $w->whereIn(\DB::raw('LOWER(job_type)'), $words);
            foreach ($words as $word) {
                $w->orWhereRaw('JSON_CONTAINS(CAST(LOWER(tags) AS JSON), JSON_QUOTE(?))', [$word]);
            }
        });
    }

    /** Partly paid, money still due, and no payment for $days days (Jose: "stopped payments"). */
    public static function stopped(Builder $q, int $days): Builder
    {
        return JobMoney::due($q)->whereIn('id', JobMoney::lastPaidBefore(now()->subDays($days)));
    }
}
