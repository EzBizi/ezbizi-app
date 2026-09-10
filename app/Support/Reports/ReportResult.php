<?php

namespace App\Support\Reports;

/** One run of one report (spec 2026-09-10). */
final readonly class ReportResult
{
    public function __construct(
        /** @var list<array{key: string, label: string, type: string}> */
        public array $columns,
        /** @var list<array<string, mixed>> */
        public array $rows,
        /** True when the row cap cut the result short. */
        public bool $capped,
        public int $count,
    ) {}
}
