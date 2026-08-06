<?php

namespace App\Console\Commands;

use App\Support\Mail\MailStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Prove mail works, end to end, against the real transport.
 *
 * Sent synchronously and deliberately NOT queued: the whole point is to see the
 * transport's own error. A queued test that fails silently in a worker log
 * answers the opposite of the question being asked.
 */
class SendTestEmail extends Command
{
    protected $signature = 'ezbizi:mail-test {address : Where to send it}';

    protected $description = 'Send a real email through the configured transport and report what happened';

    public function handle(MailStatus $status): int
    {
        $summary = $status->summary();

        $this->line("transport: {$summary['transport']}");
        $this->line('from:      '.($summary['from'] ?? '(unset)'));

        if (! $summary['delivers']) {
            $this->error('This installation cannot deliver mail.');

            foreach ($summary['missing'] as $name) {
                $this->line("  missing: {$name}");
            }

            if ($summary['missing'] === []) {
                $this->line("  the '{$summary['transport']}' transport does not send anywhere.");
            }

            // Still a failure exit even though nothing was attempted — a check
            // that reports success when it did not send is the bug this command
            // exists to prevent.
            return self::FAILURE;
        }

        $address = $this->argument('address');

        try {
            Mail::raw(
                "This is a test from EzBizi.\n\n"
                ."If you are reading it, the transport works and password resets, "
                ."invitations and backup alerts will reach people.\n\n"
                .'Sent '.now()->toDayDateTimeString().' UTC.',
                fn ($message) => $message->to($address)->subject('EzBizi mail test'),
            );
        } catch (\Throwable $e) {
            $this->error('The transport refused it:');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$address}.");
        $this->line('Accepted by the transport — check the inbox to confirm delivery.');

        return self::SUCCESS;
    }
}
