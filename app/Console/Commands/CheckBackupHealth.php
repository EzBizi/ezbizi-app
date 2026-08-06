<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Notice when the backup stops working (plan §18 Phase 0 item 5).
 *
 * ── Why the app watches this and not just the journal ────────────
 *
 * The night the production database was wiped, every check that ran afterwards
 * was a status code: 200 for a page, 401 for an endpoint, `active` for a
 * systemd unit. Not one of them touched a row, and the system looked healthy in
 * every way anybody was looking for seven hours.
 *
 * So the verdict is read here, inside the product, where somebody is already
 * looking — and a MISSING verdict counts as a failure. A timer that has stopped
 * writes nothing at all, which means "the last result was a success" is exactly
 * what a dead backup looks like.
 */
class CheckBackupHealth extends Command
{
    protected $signature = 'ezbizi:check-backups {--quiet-when-healthy}';

    protected $description = 'Read the nightly backup verdict and raise an alert if it is failed, stale or missing';

    public function handle(AuditLogger $audit, MailStatus $mail): int
    {
        $problem = $this->problem();

        if ($problem === null) {
            if (! $this->option('quiet-when-healthy')) {
                $this->info('Backups are healthy.');
            }

            return self::SUCCESS;
        }

        $this->error($problem);

        // Errors are logged even for a Lead Developer — the one exception to
        // suppression (§4.2), and this is the category it exists for.
        $audit->logError('backup.unhealthy', ['problem' => $problem]);

        $to = config('ezbizi.backups.alert_to');

        if ($mail->delivers() && filled($to)) {
            Mail::raw(
                "The EzBizi backup is not healthy.\n\n{$problem}\n\n"
                ."Checked ".now()->toDayDateTimeString()." UTC.\n"
                ."Run `sudo /usr/local/bin/ezbizi-backup.sh && sudo /usr/local/bin/ezbizi-restore-check.sh` "
                ."on the box to see the failure directly.",
                fn ($m) => $m->to($to)->subject('EzBizi backup needs attention'),
            );
        }

        return self::FAILURE;
    }

    /** The problem in one sentence, or null if there isn't one. */
    private function problem(): ?string
    {
        $path = config('ezbizi.backups.status_file');

        if (! is_readable($path)) {
            return 'No backup verdict has ever been written. The nightly timer has '
                .'either never run or cannot write to '.$path.'.';
        }

        $status = json_decode((string) file_get_contents($path), true);

        if (! is_array($status) || ! isset($status['at'])) {
            return 'The backup verdict at '.$path.' is unreadable.';
        }

        $at  = Carbon::parse($status['at']);
        $age = $at->diffInHours(now());

        if ($age > config('ezbizi.backups.max_age_hours')) {
            return sprintf(
                'The last backup verdict is %d hours old (%s). The nightly timer has stopped running.',
                $age, $at->toDayDateTimeString(),
            );
        }

        if (($status['ok'] ?? false) !== true) {
            return sprintf(
                'The last backup failed at the %s stage: %s',
                $status['stage'] ?? 'unknown',
                implode(', ', $status['errors'] ?? ['no reason recorded']),
            );
        }

        // A backup that was written but never verified is the case this whole
        // item exists to close, so it is a problem in its own right.
        if (($status['stage'] ?? '') !== 'restore-check') {
            return 'The backup was written but its restore was never verified — '
                .'the check either did not run or did not finish.';
        }

        return null;
    }
}
