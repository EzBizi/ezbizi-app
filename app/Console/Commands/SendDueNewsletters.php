<?php

namespace App\Console\Commands;

use App\Jobs\SendNewsletter;
use App\Models\Newsletter;
use App\Models\NewsletterSend;
use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Newsletter\NewsletterSenders;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Scheduled sends retain their account and are claimed inside their tenant transaction. */
class SendDueNewsletters extends Command
{
    protected $signature = 'ezbizi:send-due-newsletters';

    protected $description = 'Send scheduled newsletters whose time has arrived';

    public function handle(TenantContext $tenant): int
    {
        $waiting = NewsletterSend::withoutGlobalScopes()->where('status', NewsletterSend::STATUS_QUEUED)->get();
        foreach ($waiting as $pending) {
            $organization = Organization::withoutGlobalScopes()->find($pending->organization_id);
            if ($organization === null) {
                continue;
            }
            try {
                $tenant->run($organization, function () use ($pending) {
                    DB::transaction(function () use ($pending) {
                        // Match the admission/edit lock order: newsletter, then send.
                        $newsletter = Newsletter::query()->lockForUpdate()->find($pending->newsletter_id);
                        $send = NewsletterSend::query()->lockForUpdate()->find($pending->id);
                        if ($newsletter === null || $send === null || $send->status !== NewsletterSend::STATUS_QUEUED || ! $newsletter->isDue()) {
                            return;
                        }
                        $connection = $send->connection_id === null ? null : SocialConnection::find($send->connection_id);
                        if ($connection === null || $connection->provider !== $send->provider
                            || NewsletterSenders::for($connection->providerEnum()) === null) {
                            $send->markFailed('The original mailing account is unavailable or was not recorded. Review the mailing and send again.');
                            $newsletter->forceFill(['status' => Newsletter::STATUS_FAILED])->save();

                            return;
                        }
                        $send->forceFill(['status' => NewsletterSend::STATUS_SENDING])->save();
                        $newsletter->forceFill(['status' => Newsletter::STATUS_SENDING])->save();
                        SendNewsletter::dispatch($send->id, $connection->id)->afterCommit();
                    });
                });
            } catch (Throwable $e) {
                // One lapsed tenant or broken row must not prevent other tenants' sends.
                report($e);
                $this->warn('A scheduled mailing could not be released; see the application log.');
            }
        }

        return self::SUCCESS;
    }
}
