<?php

namespace App\Support\Scheduling;

use App\Models\Job;
use App\Support\Audit\AuditLogger;
use App\Support\Workiz\WorkizMirror;
use Carbon\CarbonImmutable;

/**
 * Moves a job to a day and window: EzBizi first, then mirrored to Workiz
 * (Jose, 2026-10-27). The job starts at the window's start and keeps its own
 * length; one with no length on record takes the window's. Capacity follows
 * by itself, since availability counts jobs.
 */
final class JobMove
{
    public static function to(Job $job, string $date, string $startTime, string $endTime, array $metadata = []): void
    {
        // Wall clock in, wall clock out: parsed and formatted in one zone, so nothing converts.
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$startTime}", 'UTC');
        $minutes = (int) ($job->duration_minutes ?: $start->diffInMinutes(CarbonImmutable::createFromFormat('Y-m-d H:i', "{$date} {$endTime}", 'UTC')));
        $was = $job->getRawOriginal('scheduled_for');
        $job->forceFill(['scheduled_for' => $start->format('Y-m-d H:i:s'), 'duration_minutes' => $minutes])->save();
        app(AuditLogger::class)->log('scheduling.job_rescheduled', $job, metadata: ['from' => $was, 'to' => $start->format('Y-m-d H:i')] + $metadata);
        WorkizMirror::queue($job, 'reschedule', ['start' => $start->format('Y-m-d H:i:s'), 'end' => $start->addMinutes($minutes)->format('Y-m-d H:i:s')]);
    }
}
