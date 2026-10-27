<?php

namespace App\Console\Commands;

use App\Models\ClientIssue;
use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Alert when an app version suddenly reports far more problems than usual
 * (Jose, 2026-10-15, with the client error log). Same ops address and the
 * same once-per-six-hours rule as the backup and Grok alerts.
 *
 * A spike = at least MIN_HOURLY reports in the last hour for one version, AND
 * at least FACTOR times that version's average hour over the day before. A
 * brand-new version has no day before; MIN_HOURLY alone decides for it.
 */
class CheckClientIssueSpikes extends Command
{
    protected $signature = 'ezbizi:check-client-issues';

    protected $description = 'Alert when an app version reports a sudden spike of errors, crashes or freezes';

    public const MIN_HOURLY = 25;

    public const FACTOR = 5;

    public function handle(AuditLogger $audit, MailStatus $mail): int
    {
        $spikes = self::spikes();

        foreach ($spikes as $spike) {
            $line = "{$spike['platform']} {$spike['version']}: {$spike['last_hour']} reports in the last hour "
                ."(usual hour: {$spike['usual']}).";
            $this->error($line);

            if (! Cache::add('client-issues.alerted.'.$spike['platform'].'.'.$spike['version'], true, now()->addHours(6))) {
                continue;
            }

            $audit->logError('client_issues.spike', $spike);

            $to = config('ezbizi.backups.alert_to');
            if ($mail->delivers() && filled($to)) {
                Mail::raw(
                    "An EzBizi app version is reporting far more problems than usual.\n\n{$line}\n\n"
                    .'Details: staff console, Health, App issues.',
                    fn ($m) => $m->to($to)->subject("EzBizi: error spike on {$spike['platform']} {$spike['version']}"),
                );
            }
        }

        return $spikes === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{platform: ?string, version: ?string, last_hour: int, usual: float}> */
    public static function spikes(): array
    {
        $hour = now()->subHour();
        $day = now()->subDay()->subHour();

        // An offline phone flushes every failed background poll on reconnect;
        // that is the network, not the app (0.0.0461, 2026-10-27).
        $recent = ClientIssue::query()
            ->where('message', 'not like', '%could not reach the server%')
            ->where('last_seen_at', '>=', $hour)
            ->groupBy('platform', 'app_version')
            ->select(['platform', 'app_version', DB::raw('SUM(`count`) as n')])
            ->get();

        $spikes = [];
        foreach ($recent as $row) {
            $n = (int) $row->n;
            if ($n < self::MIN_HOURLY) {
                continue;
            }

            $before = (int) ClientIssue::query()
                ->where('message', 'not like', '%could not reach the server%')
                ->where('platform', $row->platform)
                ->where('app_version', $row->app_version)
                ->whereBetween('last_seen_at', [$day, $hour])
                ->sum('count');
            $usual = round($before / 24, 1);

            if ($usual > 0 && $n < self::FACTOR * $usual) {
                continue;
            }

            $spikes[] = [
                'platform' => $row->platform,
                'version' => $row->app_version,
                'last_hour' => $n,
                'usual' => $usual,
            ];
        }

        return $spikes;
    }
}
