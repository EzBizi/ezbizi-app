<?php

namespace App\Console\Commands;

use App\Models\Mailbox;
use App\Models\Organization;
use App\Support\Email\MailboxReaders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Receive and Send on the inbox card, pass or fail (Jose 11-12: a connection
 * takes care of its own setup and says whether it works).
 *
 * A connect clears `send_checked_at`, so a new or reconnected inbox is checked
 * within the minute; every other inbox once a day.
 *
 *  - Receive: one real read of the inbox through the recording wrapper, which
 *    stamps `last_reached_at` or marks it broken with the reason. So an IMAP
 *    password is proven by a login, not just stored.
 *  - Send: each reader's `sendProblem`, a token and its send permission, or
 *    the SMTP sign-in. Nothing is ever sent.
 *
 * ponytail: a few per minute, oldest first; a thousand inboxes take a few hours
 * to go round, which is fine for a daily check.
 */
class CheckMailboxes extends Command
{
    protected $signature = 'ezbizi:check-mailboxes {--limit=5}';

    protected $description = 'Check each connected inbox can receive and send, without sending anything';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly MailboxReaders $readers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $due = Mailbox::withoutGlobalScopes()
            ->where('status', Mailbox::STATUS_CONNECTED)
            ->where(fn ($q) => $q->whereNull('send_checked_at')->orWhere('send_checked_at', '<', now()->subDay()))
            ->orderByRaw('send_checked_at is not null, send_checked_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($due as $mailbox) {
            $organization = Organization::withoutGlobalScopes()->find($mailbox->organization_id);

            if ($organization === null) {
                continue;
            }

            $this->tenant->run($organization, function () use ($mailbox) {
                try {
                    $this->readers->for($mailbox)->list($mailbox, null, 1, 'INBOX');
                } catch (\Throwable) {
                    // Recorded on the row by the wrapper: broken, and why.
                }

                $mailbox->refresh()->forceFill([
                    'send_checked_at' => now(),
                    'send_problem'    => $this->problem($mailbox),
                ])->save();
            });
        }

        return self::SUCCESS;
    }

    private function problem(Mailbox $mailbox): ?string
    {
        $reader = $this->readers->for($mailbox);
        $reader = property_exists($reader, 'inner') ? $reader->inner : $reader;

        if (! method_exists($reader, 'sendProblem')) {
            return null; // ponytail: a provider with no check is not reported as failing.
        }

        try {
            return $reader->sendProblem($mailbox);
        } catch (\Throwable $e) {
            report($e);

            return 'The sending check could not run. It is tried again tomorrow.';
        }
    }
}
