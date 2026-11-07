<?php

namespace App\Console\Commands;

use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\PhoneAuditAlert;
use App\Support\Phone\TwilioGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every two weeks, after hours: ring each of our lines once and check it answered (Jose,
 * 2026-11-03). From the platform account, to our own numbers only: it never
 * dials anything that is not a row in phone_numbers. Each call lands in that
 * company's Calls through the normal voice webhook. Failures alert.
 */
class PhoneLineAnswerTest extends Command
{
    protected $signature = 'ezbizi:phone-line-answer-test
        {--wait=60 : Seconds before reading each call\'s result}
        {--all : Ring every line now, whatever its local hour}';

    /**
     * After hours, so it reaches voicemail and rings nobody (Jose, 2026-11-03).
     * Run hourly; each line is rung in the hour this is, in its company's zone.
     */
    private const LOCAL_HOUR = 21;

    protected $description = 'Ring every phone line once and alert on the ones that did not answer';

    public function handle(TwilioGateway $twilio): int
    {
        // Listen a few seconds after the line picks up, then leave.
        $twiml = '<Response><Pause length="6"/><Hangup/></Response>';
        $total = 0;
        $failed = 0;

        // Each organization rings its own lines from its own test number (phone.audit_call_from).
        $byOrganization = PhoneNumber::query()->withoutGlobalScopes()
            ->whereIn('connection_id', PhoneConnection::query()->withoutGlobalScopes()->where('status', PhoneConnection::STATUS_CONNECTED)->select('id'))
            ->get()
            ->groupBy('organization_id');

        foreach ($byOrganization as $organizationId => $numbers) {
            $organization = \App\Models\Organization::query()->find($organizationId);
            $from = $organization === null ? '' : trim((string) PhoneAuditAlert::setting($organization, 'phone.audit_call_from'));
            $caller = $numbers->firstWhere('e164', $from);
            if ($caller === null) {
                continue;
            }
            $account = (string) ($caller->account_sid ?: PhoneConnection::query()->withoutGlobalScopes()->whereKey($caller->connection_id)->value('account_sid'));
            $lines = $numbers->where('e164', '!=', $from)
                ->filter(fn ($n) => $this->option('all') || self::due(now(self::zone((string) $organizationId))))
                ->unique('e164');
            $issues = $this->ring($twilio, $account, $from, $twiml, $lines);
            PhoneAuditAlert::send($organization, 'line answer test', $issues);
            $total += $lines->count();
            $failed += count($issues);
        }
        $this->info("{$total} lines rung, {$failed} failed.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function ring(TwilioGateway $twilio, string $account, string $from, string $twiml, \Illuminate\Support\Collection $lines): array
    {
        $calls = [];
        $issues = [];
        foreach ($lines as $line) {
            $e164 = (string) $line->e164;

            // Only a line that is closed right now and ends in voicemail (or a
            // closed message) rings nobody; anything else would wake somebody.
            if (! self::ringsNobody($line)) {
                $issues[] = "{$e164}: not tested, rings people after hours";

                continue;
            }

            try {
                $calls[$e164] = $twilio->platformCall($account, $from, $e164, $twiml);
            } catch (Throwable $e) {
                $issues[] = "{$e164}: could not place the test call: {$e->getMessage()}";
            }
        }

        if ($calls !== []) {
            sleep(max(0, (int) $this->option('wait')));
        }

        foreach ($calls as $e164 => $sid) {
            $result = $twilio->platformCallStatus($account, $sid);
            if ($result['status'] !== 'completed' || $result['duration'] < 1) {
                $issues[] = "{$e164}: did not answer ({$result['status']})";
            }
        }

        foreach ($issues as $issue) {
            $this->warn($issue);
        }

        return $issues;
    }

    private static function ringsNobody(PhoneNumber $line): bool
    {
        $organization = \App\Models\Organization::query()->find($line->organization_id);

        return $organization !== null && app(\App\Support\Tenancy\TenantContext::class)->run($organization, function () use ($line) {
            $schedule = $line->resolved('hours_schedule_id') === null
                ? null
                : \App\Models\PhoneHoursSchedule::query()->find($line->resolved('hours_schedule_id'));

            return $schedule !== null
                && ! \App\Support\Phone\HoursClock::open($schedule, new \DateTimeImmutable('now'))
                && $line->resolved('after_hours') !== PhoneNumber::AFTER_HOURS_FORWARD;
        });
    }

    /** An even-week Monday at 21:xx, in the line's own zone. */
    private static function due(\Illuminate\Support\Carbon $local): bool
    {
        return $local->isMonday() && $local->isoWeek() % 2 === 0 && (int) $local->format('G') === self::LOCAL_HOUR;
    }

    private static function zone(string $organizationId): string
    {
        static $zones = [];

        return $zones[$organizationId] ??= (string) (($organization = \App\Models\Organization::query()->find($organizationId)) === null
            ? config('app.timezone')
            : app(\App\Support\Tenancy\TenantContext::class)->run($organization, fn () => app(\App\Support\Settings\SettingsResolver::class)->get('timezone', $organization)));
    }
}
