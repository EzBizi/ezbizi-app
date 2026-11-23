<?php

namespace App\Http\Controllers\Api;

use App\Models\AutomationEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Mail\MailStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * The subsystems that fail silently, in one place (plan §4.2).
 *
 * ── Why this screen exists ───────────────────────────────────────
 *
 * Everything here has the same shape of failure: it stops, and nothing about
 * the app looks different. The scheduler was not running for the entire first
 * week — no error, no alert, the outbox simply filled up. Mail is a `log`
 * transport that accepts every message and returns success. The backup wrote
 * files to the same disk it was protecting and reported an exit code of zero.
 * The TLS certificate is the same shape with a date attached: it works
 * perfectly right up to the minute it stops working for everyone at once.
 *
 * A status code cannot see any of that. This asks the questions that can:
 * when did it last actually work, and is that recent enough.
 *
 * Staff-only, because the answers name hosts, transports and file paths.
 */
class HealthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, MailStatus $mail): array
    {
        return [
            'backups'  => $this->backups(),
            'mail'     => $mail->summary(),
            'outbox'   => $this->outbox(),
            'certificate' => $this->certificate(),
            'peer'     => $this->peer(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Send a real email, synchronously, and say exactly what happened.
     *
     * Not queued: a test whose failure lands in a worker log answers the
     * opposite of the question being asked.
     */
    public function testEmail(Request $request, MailStatus $mail): array
    {
        $input = Validator::make($request->all(), [
            'address' => ['required', 'email'],
        ])->validate();

        if (! $mail->delivers()) {
            return [
                'sent'    => false,
                'message' => 'This installation cannot deliver mail yet.',
                'missing' => $mail->missing(),
            ];
        }

        try {
            Mail::raw(
                "This is a test from EzBizi.\n\nIf you are reading it, password resets, "
                ."invitations and backup alerts will reach people.\n\n"
                .'Sent '.now()->toDayDateTimeString().' UTC.',
                fn ($m) => $m->to($input['address'])->subject('EzBizi mail test'),
            );
        } catch (\Throwable $e) {
            // The transport's own words. A generic "could not send" here would
            // hide the one useful sentence — SES in particular says precisely
            // which identity or credential it objected to.
            return ['sent' => false, 'message' => $e->getMessage(), 'missing' => []];
        }

        /*
         * Recorded, because this is the one control on the health screen that
         * does something rather than reporting something.
         *
         * It sends real mail, from the platform's own identity, to any address
         * the caller types. That is a small outbound capability held by every
         * staff tier, and an unrecorded one is indistinguishable from somebody
         * testing whether the box can reach their own mailbox. logStaff, not
         * log: the actor is a PlatformStaff, and this must be written even for
         * the account the exemption covers.
         */
        $staff = $request->user();

        $this->audit->logStaff(
            action: 'staff.test_email_sent',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            metadata: ['address' => $input['address']],
        );

        return [
            'sent'    => true,
            'message' => "Accepted by the transport for {$input['address']}.",
            'missing' => [],
        ];
    }

    private function backups(): array
    {
        $path = config('ezbizi.backups.status_file');

        if (! is_readable($path)) {
            return [
                'ok'      => false,
                'problem' => 'No verdict has ever been written. The nightly timer has not run.',
            ];
        }

        $status = json_decode((string) file_get_contents($path), true) ?: [];
        $at     = isset($status['at']) ? Carbon::parse($status['at']) : null;
        $age    = $at === null ? null : (int) $at->diffInHours(now());

        // Age is judged here rather than trusting `ok`, because a stopped timer
        // leaves yesterday's success sitting in the file indefinitely.
        $stale = $at === null || $age > config('ezbizi.backups.max_age_hours');

        return [
            'ok'         => ! $stale && ($status['ok'] ?? false) === true
                            && ($status['stage'] ?? '') === 'restore-check',
            'at'         => $at?->toIso8601String(),
            'age_hours'  => $age,
            'stage'      => $status['stage'] ?? null,
            'verified'   => ($status['stage'] ?? '') === 'restore-check' && isset($status['tables']),

            // Reported separately from `ok` because they fail differently: a
            // restore that works proves the dump is good, and an off-site copy
            // proves it will still exist. A green tick for the first while the
            // second is missing is the reassurance this screen exists to avoid.
            'offsite'    => $status['offsite'] ?? false,
            'restored_from' => $status['from'] ?? null,
            'errors'     => $status['errors'] ?? [],

            // Never null when ok is false. The card renders `problem` as its
            // explanation, and a fresh backup that simply has not been
            // restore-checked yet produced ok=false, problem=null, errors=[] —
            // a red icon beside an empty sentence, which is the least useful
            // thing a health screen can do.
            'problem'    => $this->problem($status, $stale),
        ];
    }

    private function problem(array $status, bool $stale): ?string
    {
        if ($stale) {
            return 'The verdict is stale — the nightly timer has stopped.';
        }

        if (($status['errors'] ?? []) !== []) {
            return implode(' ', $status['errors']);
        }

        if (($status['stage'] ?? '') !== 'restore-check') {
            return 'The backup was written but its restore has not been verified yet. '
                .'An unverified backup is indistinguishable from a working one until '
                .'the day it matters.';
        }

        return ($status['ok'] ?? false) === true
            ? null
            : 'The restore check did not pass, and did not say why.';
    }

    /**
     * The outbox is the canary for the scheduler.
     *
     * If `schedule:run` stops, undelivered events pile up and nothing else
     * changes — which is exactly what happened for a week. An oldest-undelivered
     * age is a direct read of whether the sweep is running.
     */
    /**
     * Whether the other box last saw this one, and whether that reading is fresh.
     *
     * Written by deploy/watch-peer-websites.sh on a timer. A missing file is a
     * failure: the timer that has never run looks identical to one that stopped.
     */
    private function peer(): array
    {
        $url  = trim((string) config('ezbizi.peer.url'));
        $host = parse_url($url, PHP_URL_HOST) ?: 'the other box';

        if ($url === '') {
            return [
                'ok'      => false,
                'host'    => $host,
                'problem' => 'EZBIZI_PEER_URL is not set, so this screen cannot name the other '
                    .'box. The watchdog has nothing to ask — and the old default was this box '
                    .'asking itself whether it was alive.',
            ];
        }

        $path = (string) config('ezbizi.peer.status_file');

        if (! is_readable($path)) {
            return [
                'ok'      => false,
                'host'    => $host,
                'problem' => 'No peer verdict has ever been written. The watchdog has not run.',
            ];
        }

        $status = json_decode((string) file_get_contents($path), true) ?: [];
        $at     = isset($status['at']) ? Carbon::parse($status['at']) : null;
        $age    = $at === null ? null : (int) $at->diffInMinutes(now());
        $stale  = $at === null || $age > (int) config('ezbizi.peer.max_age_minutes');
        $ok     = ! $stale && ($status['ok'] ?? false) === true;

        return [
            'ok'      => $ok,
            'host'    => $status['host'] ?? $host,
            'at'      => $at?->toIso8601String(),
            'problem' => $ok ? null : (
                $stale
                    ? 'The other box has not been heard from recently. The watchdog has stopped or the peer is down.'
                    : (string) ($status['problem'] ?? 'The other box did not answer.')
            ),
        ];
    }

    private function outbox(): array
    {
        // Overdue counts from available_at: a delayed rule (e.g. "call not
        // booked" two hours later) waits on purpose, and read as a stalled
        // scheduler when timed from occurred_at (11-11).
        $oldest = AutomationEvent::withoutGlobalScopes()
            ->whereNull('delivered_at')
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->value('available_at');

        return [
            'pending'        => AutomationEvent::withoutGlobalScopes()->whereNull('delivered_at')->count(),
            'oldest_minutes' => $oldest ? (int) Carbon::parse($oldest)->diffInMinutes(now()) : null,
            'failed_jobs'    => DB::table('failed_jobs')->count(),
            'ok'             => $oldest === null || Carbon::parse($oldest)->diffInMinutes(now()) < 15,
        ];
    }

    /**
     * How long the certificate visitors are actually being handed has left.
     *
     * Read off the wire rather than off the disk, and that distinction is the
     * whole point. nginx loads its certificates once, at master-process start,
     * and serves that copy until something reloads it — so certbot writing a
     * renewed file changes what is on disk and nothing about what is served. A
     * check that opened /etc/letsencrypt would have reported ninety days left
     * while every visitor was being handed a certificate counting down to zero.
     * (It also could not have run: that tree is root-only and this is www-data.)
     *
     * A reading that cannot be taken is a failure, not an unknown — the same rule
     * the backup verdict follows, and for the same reason. "I could not tell" and
     * "it is fine" are the same green tick to anyone glancing at this screen, and
     * only one of them is true.
     */
    private function certificate(): array
    {
        $host    = (string) config('ezbizi.tls.host');
        $port    = (int) config('ezbizi.tls.port');
        $minDays = (int) config('ezbizi.tls.min_days');

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,

            // Verification deliberately off. The question is when this expires,
            // and an already-expired or mismatched certificate is precisely the
            // case that has to produce an answer — with verification on, the one
            // reading that matters most becomes a refused connection instead.
            'verify_peer'       => false,
            'verify_peer_name'  => false,

            // Still sent, because the host serves two names off one address and
            // without SNI it would hand back whichever it lists first — which is
            // not necessarily the certificate anyone is being served.
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $error,
            (float) config('ezbizi.tls.timeout'),
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            return $this->noCertificateReading(
                $host,
                "Nothing answered TLS on {$host}:{$port} — ".(trim((string) $error) ?: 'the connection failed').'.',
            );
        }

        $peer = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        $parsed = $peer === null ? [] : (openssl_x509_parse($peer) ?: []);

        if (! isset($parsed['validTo_time_t'])) {
            return $this->noCertificateReading($host, "{$host} answered, but its certificate could not be parsed.");
        }

        // Whole days from raw timestamps rather than a Carbon diff: this number
        // is read as "days left" and rounding it the wrong way at the boundary
        // turns the last day of warning into no warning.
        $expires = Carbon::createFromTimestampUTC($parsed['validTo_time_t']);
        $days    = (int) floor(($expires->getTimestamp() - now()->getTimestamp()) / 86400);

        return [
            'ok'             => $days >= $minDays,
            'host'           => $host,
            'common_name'    => $parsed['subject']['CN'] ?? null,
            'expires_at'     => $expires->toIso8601String(),
            'days_remaining' => $days,
            'problem'        => $days >= $minDays ? null : $this->certificateProblem($days, $minDays),
        ];
    }

    /**
     * The shape returned when there is no reading at all.
     *
     * `days_remaining` stays null rather than becoming 0, because 0 is a
     * measurement — it says the certificate expires today — and this is the
     * absence of one.
     */
    private function noCertificateReading(string $host, string $problem): array
    {
        return [
            'ok'             => false,
            'host'           => $host,
            'common_name'    => null,
            'expires_at'     => null,
            'days_remaining' => null,
            'problem'        => $problem.' A certificate nobody can read is reported as a failure,'
                .' because "I could not tell" and "it is fine" look identical on this screen.',
        ];
    }

    private function certificateProblem(int $days, int $minDays): string
    {
        if ($days < 0) {
            return 'This certificate EXPIRED '.abs($days).' day(s) ago. Both hostnames are being '
                .'refused by every browser right now.';
        }

        return "This certificate expires in {$days} day(s), under the {$minDays} it should never "
            .'drop below. certbot starts renewing at 30 days out, so either the renewal is failing '
            .'or nginx has not been reloaded since it succeeded — nginx keeps certificates in '
            .'memory and serves the copy it started with until something reloads it. Check '
            .'`certbot renew --dry-run` and /etc/letsencrypt/renewal-hooks/deploy/nginx-reload.sh.';
    }
}
