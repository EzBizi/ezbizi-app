<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * Run by deploy/ezbizi-scheduler-failed@.service when a scheduler unit fails
 * (OnFailure=), most often killed by its own timeout. Audited every time;
 * mailed at most once an hour per unit, the way check-client-issues mails.
 */
class SchedulerFailed extends Command
{
    protected $signature = 'ezbizi:scheduler-failed {unit : the systemd unit that failed}';

    protected $description = 'Alert that a scheduler slot failed or was killed by its timeout';

    public function handle(AuditLogger $audit, MailStatus $mail): int
    {
        $unit = (string) $this->argument('unit');
        $audit->logError('scheduler.unit_failed', ['unit' => $unit]);
        $this->error("Scheduler unit failed: {$unit}");

        $to = config('ezbizi.backups.alert_to');
        if (Cache::add('scheduler.failed.alerted.'.$unit, true, now()->addHour()) && $mail->delivers() && filled($to)) {
            Mail::raw(
                "The EzBizi scheduler unit {$unit} failed at ".now()->toDateTimeString()." UTC (usually: killed by its timeout).\n\n"
                ."Commands it was running may have stopped mid-pass and left their locks held.\n"
                ."On the box: journalctl -u {$unit} --since '-15 min'",
                fn ($m) => $m->to($to)->subject("EzBizi: scheduler {$unit} failed"),
            );
        }

        return self::SUCCESS;
    }
}
