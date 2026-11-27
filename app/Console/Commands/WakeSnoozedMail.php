<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\InboxController;
use App\Models\EmailSnooze;
use App\Models\Mailbox;
use App\Support\Email\CannotChangeMail;
use App\Support\Email\CannotReadMailbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Snoozed mail back in the Inbox when its time comes (design 137). Every minute. */
class WakeSnoozedMail extends Command
{
    protected $signature = 'ezbizi:wake-snoozed-mail';

    protected $description = 'Return snoozed email conversations to the Inbox when their time passes';

    public function handle(TenantContext $tenant, InboxController $inbox): int
    {
        $due = $tenant->runWithoutTenant(fn () => EmailSnooze::query()->where('until', '<=', now())->orderBy('until')->limit(200)->get());

        foreach ($due as $snooze) {
            $mailbox = $tenant->runWithoutTenant(fn () => Mailbox::query()->find($snooze->mailbox_id));

            // A snooze that can never wake is dropped, not retried every
            // minute ahead of live ones (audit 11-27).
            if ($mailbox === null) {
                $tenant->runWithoutTenant(fn () => $snooze->delete());

                continue;
            }

            $tenant->run($mailbox->organization, function () use ($inbox, $mailbox, $snooze) {
                try {
                    $inbox->wake($mailbox, $snooze->message_id);
                } catch (CannotChangeMail $e) {
                    $snooze->delete();   // refused for good: the message is gone
                } catch (CannotReadMailbox $e) {
                    report($e);   // tried again next minute
                }
            });
        }

        return self::SUCCESS;
    }
}
