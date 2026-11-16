<?php

namespace App\Support\Jobs;

use App\Models\Automation;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\WorkizJob;
use App\Support\Audit\AuditLogger;
use App\Support\Phone\NumberNames;
use App\Support\Settings\Lists\BusinessLists;
use App\Support\Workiz\WorkizClock;
use App\Support\Workiz\WorkizJobWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A job's source from the line its client dialed (Jose, 2026-11-16: "when
 * number dialed is PPC it should change the source to PPC both in ezbizi and
 * workiz"). The line → source pairs are the business's list
 * `jobs.sources_by_line`; Workiz is written first, because the reports read
 * its copy and the next sync would put the old source back otherwise.
 *
 * Going forward only: a job booked before the rule was made is left alone
 * (Jose decides any backfill).
 */
class SourceFromDialed
{
    /** How far back each run looks: a job's Workiz call can be tied to it a while after it is booked. */
    public const LOOK_BACK_HOURS = 48;

    public function __construct(
        private readonly DialedLines $lines,
        private readonly BusinessLists $lists,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Each job whose dialed line names a different source: [workiz job, from, to, line].
     *
     * @return list<array{0: WorkizJob, 1: ?string, 2: string, 3: string}>
     */
    public function changes(CarbonImmutable $since, ?string $franchiseId = null): array
    {
        $jobs = WorkizJob::query()->where('booked_at', '>=', $since->toDateTimeString())
            ->when($franchiseId, fn ($q) => $q->where('franchise_id', $franchiseId))->get();
        if ($jobs->isEmpty() || ($rules = $this->lists->rows('jobs.sources_by_line')) === []) {
            return [];
        }
        $zones = Franchise::query()->whereIn('id', $jobs->pluck('franchise_id')->unique())->get()
            ->mapWithKeys(fn (Franchise $f) => [$f->getKey() => WorkizClock::zone($f)]);
        $rows = $jobs->map(fn (WorkizJob $j) => (object) [
            'workiz_id' => $j->remote_id, 'v2_id' => $j->v2_id, 'created_at' => $j->getRawOriginal('booked_at'),
            'phone' => data_get($j->payload, 'Phone'), 'phone2' => data_get($j->payload, 'SecondPhone'),
            'clock' => $zones[$j->franchise_id] ?? null, 'timezone' => null,
        ]);
        [$dialed] = $this->lines->forJobs($rows, $since, (string) config('app.timezone'));
        $names = $this->lines->names();

        $out = [];
        foreach ($jobs as $job) {
            $number = $dialed[$job->remote_id][0] ?? null;
            $to = $number === null ? null : self::sourceFor($rules, $number, $names[$number] ?? null);
            if ($to !== null && mb_strtolower(trim((string) $job->job_source)) !== mb_strtolower($to)) {
                $out[] = [$job, $job->job_source, $to, $names[$number] ?? $number];
            }
        }

        return $out;
    }

    /**
     * Changes the jobs, Workiz first. Returns [changed, refusals].
     *
     * @return array{0: int, 1: list<string>}
     */
    public function apply(Automation $rule): array
    {
        $since = CarbonImmutable::parse($rule->created_at)->max(CarbonImmutable::now()->subHours(self::LOOK_BACK_HOURS));
        $changed = 0;
        $refused = [];
        foreach ($this->changes($since, $rule->scope_type === 'franchise' ? $rule->scope_id : null) as [$mirror, $from, $to, $line]) {
            try {
                $this->writeToWorkiz($mirror, $to);
            } catch (RuntimeException $e) {
                $refused[] = "Job {$mirror->serial_id}: {$e->getMessage()}";

                continue;
            }
            $reason = "dialed {$line}";
            if (($job = Job::query()->find($mirror->job_id)) !== null) {
                $job->forceFill(['job_source' => $to])->save();
            }
            $this->audit->log('job.source_changed', $job ?? $mirror, ['job_source' => ['from' => $from, 'to' => $to]],
                ['reason' => $reason, 'automation_id' => $rule->getKey(), 'workiz_job' => $mirror->remote_id]);
            $changed++;
        }

        return [$changed, $refused];
    }

    /** Workiz's own writer, which checks the name against the branch's list (WorkizJobWriter is final; tests override this). */
    protected function writeToWorkiz(WorkizJob $mirror, string $source): void
    {
        app(WorkizJobWriter::class)->setSource($mirror, $source);
    }

    /**
     * The source a line names: its number, or the end of its name ("NJ - PPC"
     * ends with "- PPC"), as the list says; null when no row matches.
     *
     * @param  list<array{key: string, value: array<string, mixed>}>  $rules
     */
    public static function sourceFor(array $rules, string $number, ?string $name): ?string
    {
        foreach ($rules as $row) {
            $line = trim((string) ($row['value']['line'] ?? $row['key'] ?? ''));
            $source = trim((string) ($row['value']['source'] ?? ''));
            if ($line === '' || $source === '') {
                continue;
            }
            $digits = NumberNames::lastTen($line);
            if ($digits !== null ? $digits === NumberNames::lastTen($number)
                : ($name !== null && str_ends_with(mb_strtolower(trim($name)), mb_strtolower($line)))) {
                return $source;
            }
        }

        return null;
    }
}
