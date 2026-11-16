<?php

namespace App\Support\Jobs;

use App\Support\Phone\NumberNames;
use App\Support\Reports\Experimental\RingCentralCallLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The line a job's client dialed (Reports › Jobs, Jose 11-11): the Workiz call
 * tied to the job, else the client's last call in before it was booked. Shared
 * by the report and the source-from-dialed automation (desk 11-16).
 *
 * Each job row needs workiz_id, v2_id, phone, phone2, created_at (Workiz wall
 * clock), clock and timezone.
 */
class DialedLines
{
    /** How far before a job its client's call can be (a call Friday, booked Monday). */
    public const CALL_DAYS = 7;

    public function __construct(private readonly RingCentralCallLog $calls) {}

    /** @return array<string, string> number => line name, empty when the phone system cannot be read */
    public function names(): array
    {
        return $this->calls->configured() ? $this->calls->numberNames() : [];
    }

    /**
     * Workiz job id => [the number the client dialed, when (the job's wall clock)], and whether the call log could be read.
     *
     * @param  Collection<int, object>  $jobs
     * @return array{0: array<string, array{0: string, 1: ?string}>, 1: bool}
     */
    public function forJobs(Collection $jobs, CarbonImmutable $from, string $zone): array
    {
        // A Workiz call tied to the job says it outright.
        // called_at is the Workiz account's wall clock, as the job's own is. Oldest call wins, as it always has.
        $dialed = DB::table('workiz_calls')->where('direction', 'inbound')->whereNotNull('to_number')
            ->whereIn('job_remote_id', $jobs->pluck('v2_id')->filter()->all())->orderByDesc('called_at')
            ->get(['job_remote_id', 'to_number', 'called_at'])
            ->mapWithKeys(fn (object $c) => [$c->job_remote_id => [$c->to_number, $c->called_at]])->all();
        $byJob = [];
        foreach ($jobs as $j) {
            if ($j->v2_id !== null && isset($dialed[$j->v2_id])) {
                $byJob[$j->workiz_id] = $dialed[$j->v2_id];
            }
        }

        if (! $this->calls->configured()) {
            return [$byJob, false];
        }
        try {
            // A day further back: a branch's Workiz clock can run behind the organization's.
            $log = $this->calls->forPeriod($from->setTimezone($zone)->subDays(self::CALL_DAYS + 1), CarbonImmutable::now($zone));
        } catch (Throwable $e) {
            report($e);

            return [$byJob, false];
        }

        // A Workiz call tied to the job wins; else the client's last call in before it.
        return [$byJob + self::lastCallIn($log, $jobs, $zone), true];
    }

    /**
     * Workiz job id => [the line its client last called (from either phone on the job) before
     * the job was booked (Workiz's wall clock, read in the job's ->clock, else its branch's
     * zone), within CALL_DAYS, and when, on that same clock].
     * $log newest first, as forPeriod gives it.
     *
     * @param  list<array<string, mixed>>  $log
     * @param  Collection<int, object>  $jobs
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lastCallIn(array $log, Collection $jobs, string $zone): array
    {
        $byPhone = [];
        foreach ($log as $call) {
            if (($phone = NumberNames::lastTen($call['from'] ?? null)) !== null && ($call['to_number'] ?? null) !== null) {
                $byPhone[$phone][] = [strtotime((string) $call['started_at']), (string) $call['to_number']];
            }
        }
        $byJob = [];
        foreach ($jobs as $j) {
            $booked = CarbonImmutable::parse((string) $j->created_at, $tz = ($j->clock ?? null) ?: ($j->timezone ?: $zone))->getTimestamp();
            $last = null;
            foreach (array_unique(array_filter([NumberNames::lastTen($j->phone), NumberNames::lastTen($j->phone2 ?? null)])) as $phone) {
                foreach ($byPhone[$phone] ?? [] as [$at, $number]) {
                    if ($at <= $booked + 300 && $at >= $booked - self::CALL_DAYS * 86400) {
                        $last = $last !== null && $last[0] >= $at ? $last : [$at, $number];
                        break;
                    }
                }
            }
            if ($last !== null) {
                $byJob[$j->workiz_id] = [$last[1], CarbonImmutable::createFromTimestamp($last[0], $tz)->format('Y-m-d H:i:s')];
            }
        }

        return $byJob;
    }
}
