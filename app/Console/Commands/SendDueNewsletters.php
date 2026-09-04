<?php

namespace App\Console\Commands;

use App\Jobs\SendNewsletter;
use App\Models\Newsletter;
use App\Models\NewsletterSend;
use App\Models\SocialConnection;
use App\Support\Newsletter\NewsletterSenders;
use Illuminate\Console\Command;

/**
 * Release scheduled newsletters whose time has come.
 *
 * The same sweep shape scheduled social posts use, and for the same reasons: a
 * row stops being due rather than a delayed job needing to be found and
 * cancelled, and the wall clock is resolved per row against its own timezone,
 * which SQL cannot do (TODO #20).
 *
 * The claim before dispatch is a conditional update from `queued`. As with the
 * social sweep, it is unreachable in sequence because the query already narrows
 * to that status — and it stays, because two overlapping sweeps could otherwise
 * send the same mailing to a real audience twice, and Mailchimp has no
 * idempotency key.
 */
class SendDueNewsletters extends Command
{
    protected $signature = 'ezbizi:send-due-newsletters';

    protected $description = 'Send scheduled newsletters whose time has arrived';

    public function handle(): int
    {
        $waiting = NewsletterSend::query()
            ->withoutGlobalScopes()
            ->where('status', NewsletterSend::STATUS_QUEUED)
            ->get();

        $released = 0;

        foreach ($waiting as $send) {
            $newsletter = Newsletter::query()->withoutGlobalScopes()->find($send->newsletter_id);

            if ($newsletter === null || ! $newsletter->isDue()) {
                continue;
            }

            $connection = SocialConnection::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $send->organization_id)
                ->where('provider', $send->provider)
                ->first();

            /*
             * Disconnected between scheduling and sending. Fail it with the
             * reason rather than leaving it queued forever — a mailing that
             * silently never goes is the failure this module's whole "waiting
             * is not sending" split exists to prevent.
             */
            if ($connection === null || NewsletterSenders::for($connection->providerEnum()) === null) {
                $send->markFailed(
                    'The mailing account this was going through is no longer connected here, '
                    .'so it was not sent. Reconnect it and send again.'
                );
                $newsletter->forceFill(['status' => Newsletter::STATUS_FAILED])->save();

                continue;
            }

            $claimed = NewsletterSend::query()
                ->withoutGlobalScopes()
                ->whereKey($send->getKey())
                ->where('status', NewsletterSend::STATUS_QUEUED)
                ->update(['status' => NewsletterSend::STATUS_SENDING]);

            if ($claimed === 1) {
                SendNewsletter::dispatch($send->getKey(), $connection->getKey());
                $released++;
            }
        }

        if ($released > 0) {
            $this->info("Released {$released} scheduled newsletter(s).");
        }

        return self::SUCCESS;
    }
}
