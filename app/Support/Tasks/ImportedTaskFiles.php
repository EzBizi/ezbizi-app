<?php

namespace App\Support\Tasks;

final readonly class ImportedTaskFiles
{
    /** @param list<string> $ids */
    public function __construct(public array $ids) {}
}
