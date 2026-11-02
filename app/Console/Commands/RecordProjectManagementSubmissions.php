<?php

namespace App\Console\Commands;

use App\Models\ProjectManagementSubmission;
use Illuminate\Console\Command;

/**
 * Keeps Project Management - 2's Submitted tab whole: copies each move from
 * done pending approval to Submitted, made in Workiz or in EzBizi, into
 * project_management_submissions before its event expires. Every minute; the
 * first run copies all of history. A re-run records nothing twice.
 */
class RecordProjectManagementSubmissions extends Command
{
    protected $signature = 'project-management-2:record-submissions';

    protected $description = 'Record moves from done pending approval to Submitted for Project Management - 2';

    public function handle(): int
    {
        $this->info('Recorded '.ProjectManagementSubmission::record().' submissions.');

        return self::SUCCESS;
    }
}
