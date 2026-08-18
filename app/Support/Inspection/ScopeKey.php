<?php

namespace App\Support\Inspection;

/**
 * One in-progress run per person per inspection per customer per job.
 *
 * MySQL unique indexes allow several NULLs, so unique(user, definition, job_id)
 * would let two customer-only drafts exist. This string is never null.
 */
final class ScopeKey
{
    public static function for(string $customerId, ?string $jobId): string
    {
        $job = ($jobId !== null && $jobId !== '') ? $jobId : 'none';

        return "customer:{$customerId}|job:{$job}";
    }
}
