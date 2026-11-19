<?php

namespace App\Support\Tasks;

use App\Models\Job;
use App\Models\WorkizJob;

/**
 * Jose 11-18/19: a job's number lives in its columns, the title is the name.
 * "Job #600019688 - Beau Vanderbur", "Job (600019688) Beau", "#600019688 - Beau",
 * "New job #600019688", or just "600019688" / "JOB-51758".
 */
final class JobTitle
{
    private const SHAPES = [
        '/^\s*(?:new\s+)?job\s*#\s*(\d{3,}|JOB-\d+)\s*[-:–—]?\s*(.*)$/iu',
        '/^\s*job\s*\(\s*(\d{3,})\s*\)\s*[-:–—]?\s*(.*)$/iu',
        '/^\s*#\s*(\d{3,})\s*[-:–—]\s*(.*)$/u',
        '/^\s*(\d{6,}|JOB-\d+)\s*$/iu',
    ];

    /** @return array{number: string, rest: string, ezbizi: bool}|null */
    public static function split(string $title): ?array
    {
        foreach (self::SHAPES as $re) {
            if (preg_match($re, $title, $m)) {
                return ['number' => strtoupper($m[1]), 'rest' => trim($m[2] ?? '', " -–—:\t"), 'ezbizi' => str_starts_with(strtoupper($m[1]), 'JOB-')];
            }
        }

        return null;
    }

    /** The job behind a number: Workiz's serial/remote id, or EzBizi's JOB- number. */
    public static function job(string $number): ?Job
    {
        if (str_starts_with($number, 'JOB-')) {
            return Job::query()->where('number', $number)->first();
        }
        $workiz = WorkizJob::query()->where('serial_id', $number)->orWhere('remote_id', $number)->first();

        return $workiz?->job_id ? Job::query()->find($workiz->job_id) : null;
    }
}
