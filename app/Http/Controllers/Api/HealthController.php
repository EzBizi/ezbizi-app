<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowEvent;
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
 *
 * A status code cannot see any of that. This asks the questions that can:
 * when did it last actually work, and is that recent enough.
 *
 * Staff-only, because the answers name hosts, transports and file paths.
 */
class HealthController extends Controller
{
    public function index(Request $request, MailStatus $mail): array
    {
        return [
            'backups'  => $this->backups(),
            'mail'     => $mail->summary(),
            'outbox'   => $this->outbox(),
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
        $age    = $at?->diffInHours(now());

        // Age is judged here rather than trusting `ok`, because a stopped timer
        // leaves yesterday's success sitting in the file indefinitely.
        $stale = $at === null || $age > config('ezbizi.backups.max_age_hours');

        return [
            'ok'         => ! $stale && ($status['ok'] ?? false) === true
                            && ($status['stage'] ?? '') === 'restore-check',
            'at'         => $at?->toIso8601String(),
            'age_hours'  => $age,
            'stage'      => $status['stage'] ?? null,
            'verified'   => ($status['stage'] ?? '') === 'restore-check' && ($status['ok'] ?? false),
            'restored_from' => $status['from'] ?? null,
            'errors'     => $status['errors'] ?? [],
            'problem'    => $stale ? 'The verdict is stale — the nightly timer has stopped.' : null,
        ];
    }

    /**
     * The outbox is the canary for the scheduler.
     *
     * If `schedule:run` stops, undelivered events pile up and nothing else
     * changes — which is exactly what happened for a week. An oldest-undelivered
     * age is a direct read of whether the sweep is running.
     */
    private function outbox(): array
    {
        $oldest = WorkflowEvent::withoutGlobalScopes()
            ->whereNull('delivered_at')
            ->orderBy('created_at')
            ->value('created_at');

        return [
            'pending'        => WorkflowEvent::withoutGlobalScopes()->whereNull('delivered_at')->count(),
            'oldest_minutes' => $oldest ? Carbon::parse($oldest)->diffInMinutes(now()) : null,
            'failed_jobs'    => DB::table('failed_jobs')->count(),
            'ok'             => $oldest === null || Carbon::parse($oldest)->diffInMinutes(now()) < 15,
        ];
    }
}
