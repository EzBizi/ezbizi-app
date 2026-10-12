<?php

namespace App\Console\Commands;

use App\Support\Ai\GrokCli;
use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Notice when Grok stops answering (Jose, 2026-10-12, approved "alert when
 * Grok fails"). Every module that asks Grok catches the failure and retries
 * later, so an expired sign-in used to be a warning line in the scheduler
 * output and nothing else. GrokCli stamps each success and failure; this reads
 * the stamps.
 *
 * Unhealthy = the last call failed and nothing has succeeded for 30 minutes.
 * One mail per 6 hours while it lasts, to the same ops address as backups.
 */
class CheckGrokHealth extends Command
{
    protected $signature = 'ezbizi:check-grok';

    protected $description = 'Alert when Grok has been failing for 30 minutes';

    public function handle(AuditLogger $audit, MailStatus $mail): int
    {
        $problem = self::problem();

        if ($problem === null) {
            return self::SUCCESS;
        }

        $this->error($problem);

        if (! Cache::add('grok.alerted', true, now()->addHours(6))) {
            return self::FAILURE;
        }

        $audit->logError('grok.unhealthy', ['problem' => $problem]);

        $to = config('ezbizi.backups.alert_to');

        if ($mail->delivers() && filled($to)) {
            Mail::raw(
                "Grok has stopped answering in EzBizi. Lead sorting, transcript speakers and call labels "
                ."are waiting and will catch up once it works again.\n\n{$problem}\n\n"
                ."If the sign-in expired: on the box, `sudo -u www-data HOME=/var/lib/ezbizi-grok "
                ."/var/lib/ezbizi-grok/.grok/bin/grok login`, then re-check coding_data_retention_opt_out.",
                fn ($m) => $m->to($to)->subject('EzBizi: Grok needs attention'),
            );
        }

        return self::FAILURE;
    }

    /** The problem in one sentence, or null if there isn't one. */
    public static function problem(): ?string
    {
        if (! GrokCli::configured()) {
            return null;
        }

        $failure = Cache::get('grok.last_failure');
        if (! is_array($failure)) {
            return null;
        }

        $failedAt = Carbon::parse($failure['at']);
        $ok = Cache::get('grok.last_ok');
        $okAt = $ok ? Carbon::parse($ok) : null;

        if ($okAt !== null && $okAt->gte($failedAt)) {
            return null;
        }
        if ($okAt !== null && $okAt->gt(now()->subMinutes(30))) {
            return null;
        }

        return 'Last success: '.($okAt?->toDayDateTimeString() ?? 'never').' UTC. Last failure: '
            .$failedAt->toDayDateTimeString().' UTC ('.$failure['why'].').';
    }
}
