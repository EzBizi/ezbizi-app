<?php

namespace App\Console\Commands;

use App\Support\Social\Publishing\MediaHandoff;
use Illuminate\Console\Command;

/**
 * Delete media handoffs that outlived the window they were signed for.
 *
 * Publishing releases its own handoff in a `finally`, so in the ordinary case
 * this finds nothing and that is the point — a `finally` does not run when the
 * worker is killed, the container is rescheduled, or the machine reboots
 * mid-post. Without this, every such death leaves a tenant's photo on disk
 * indefinitely.
 *
 * Anything it finds is already unfetchable: the signature expired before the
 * cutoff did. So there is nothing to weigh up and nothing to confirm.
 */
class ExpireSocialHandoffs extends Command
{
    protected $signature = 'ezbizi:expire-social-handoffs';

    protected $description = 'Delete social media handoff files left behind by an interrupted post';

    public function handle(): int
    {
        $swept = MediaHandoff::sweep();

        if ($swept > 0) {
            // Worth a line, because it is evidence something died mid-post
            // rather than routine housekeeping.
            $this->info($swept.' social handoff file(s) swept.');
        }

        return self::SUCCESS;
    }
}
