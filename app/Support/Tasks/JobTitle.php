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

    /** Row 41 (Jose 11-23): "600025660 Albert", "400595957 - Peter", "Jade 400595628". Loose: taken only when the number is a real job. */
    private const LOOSE = [
        '/^\s*(\d{6,})\s*[-:–—]?\s*(\D.*)$/u',
        '/^\s*(\D.*?)\s*[-:–—]?\s*(\d{6,})\s*$/u',
    ];

    /** @return array{number: string, rest: string, ezbizi: bool, loose?: bool}|null */
    public static function split(string $title): ?array
    {
        foreach (self::SHAPES as $re) {
            if (preg_match($re, $title, $m)) {
                return ['number' => strtoupper($m[1]), 'rest' => trim($m[2] ?? '', " -–—:\t"), 'ezbizi' => str_starts_with(strtoupper($m[1]), 'JOB-')];
            }
        }

        foreach (self::LOOSE as $i => $re) {
            if (preg_match($re, $title, $m)) {
                [$number, $rest] = $i === 0 ? [$m[1], $m[2]] : [$m[2], $m[1]];

                return ['number' => $number, 'rest' => trim($rest, " -–—:	"), 'ezbizi' => false, 'loose' => true];
            }
        }

        return null;
    }

    /** @var array<string, string>|null number => job id, for a backfill over every task (serial_id has no index) */
    private static ?array $map = null;

    /** A backfill reads the organization's Workiz numbers once; the live import asks each time. */
    public static function preload(): void
    {
        self::$map = [];
        foreach (WorkizJob::query()->whereNotNull('job_id')->get(['serial_id', 'remote_id', 'job_id']) as $w) {
            foreach (array_filter([(string) $w->serial_id, (string) $w->remote_id]) as $k) {
                self::$map[$k] ??= (string) $w->job_id;
            }
        }
    }

    public static function forget(): void
    {
        self::$map = null;
    }

    /** The job behind a number: Workiz's serial/remote id, or EzBizi's JOB- number. */
    public static function job(string $number): ?Job
    {
        if (str_starts_with($number, 'JOB-')) {
            return Job::query()->where('number', $number)->first();
        }
        if (self::$map !== null) {
            return isset(self::$map[$number]) ? Job::query()->find(self::$map[$number]) : null;
        }
        $workiz = WorkizJob::query()->where('serial_id', $number)->orWhere('remote_id', $number)->first();

        return $workiz?->job_id ? Job::query()->find($workiz->job_id) : null;
    }
}
