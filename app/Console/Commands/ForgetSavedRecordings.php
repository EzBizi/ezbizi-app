<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-time cleanup (Jose, 2026-10-27: "I don't want you saving any call
 * recordings"). Release 0.0.0498 briefly kept a copy of each Twilio recording
 * under storage/app/private/phone-recordings; this deletes all of them.
 * Nothing in the database pointed at the files (the path was derived from the
 * call id), so there is nothing else to clear. Safe to run more than once.
 */
class ForgetSavedRecordings extends Command
{
    protected $signature = 'ezbizi:forget-saved-recordings';

    protected $description = 'Delete every call recording EzBizi saved locally (one-time cleanup)';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $files = count($disk->allFiles('phone-recordings'));
        $disk->deleteDirectory('phone-recordings');

        $this->info("Deleted {$files} saved recording(s).");

        return self::SUCCESS;
    }
}
