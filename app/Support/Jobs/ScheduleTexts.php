<?php

namespace App\Support\Jobs;

use App\Models\Job;
use App\Models\JobNote;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Phone\PhoneTexts;
use App\Support\Scheduling\JobMove;
use App\Support\Settings\SettingsResolver;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Project Management's scheduling texts (Jose, 2026-11-10): "Ready to
 * schedule" texts the client the date and slot. YES schedules the job (in
 * Workiz too) and confirms; NO offers the alternative; NO again tells the
 * client the PM will call and tells the PM. Every text, both ways, is a note
 * on the job. State lives in jobs.schedule_proposal.stage:
 * offered → alt_offered → confirmed | pm_call.
 */
final class ScheduleTexts
{
    /** How long an offer waits for its answer. */
    private const REPLY_DAYS = 14;

    public function __construct(private readonly PhoneTexts $texts) {}

    /** Texts the first offer. Null when sent, else the sentence why not. */
    public function offer(Job $job, ?User $by): ?string
    {
        if (! app(SettingsResolver::class)->get('project_board.client_texts', $job->franchise ?? $job->organization)) {
            return null;
        }
        $p = (array) $job->schedule_proposal;

        return $this->send($job, $p + ['by' => $by?->id], 'offered', $this->ask($p['date'], $p['slot']));
    }

    /** An inbound text answering a pending offer; false when it is not one. */
    public function absorb(string $from, string $body, string $sid): bool
    {
        $answer = match (true) {
            (bool) preg_match('/^\s*(y|yes|yeah|yep|si|sí|ok|okay|confirm)\b/iu', $body) => 'yes',
            (bool) preg_match('/^\s*(n|no|nope|nah)\b/iu', $body) => 'no',
            default => null,
        };
        $job = $answer === null || $from === '' ? null : Job::query()
            ->whereIn('schedule_proposal->stage', ['offered', 'alt_offered'])
            ->where('updated_at', '>=', now()->subDays(self::REPLY_DAYS))
            ->whereHas('customer', fn ($q) => $q->where('phone', $from))
            ->latest('updated_at')->first();
        if ($job === null) {
            return false;
        }

        $this->note($job, 'Client replied: '.trim($body));
        $p = (array) $job->schedule_proposal;
        $alt = $p['stage'] === 'alt_offered';

        if ($answer === 'yes') {
            [$start, $end] = self::window($alt ? $p['alt_slot'] : $p['slot']);
            $date = $alt ? $p['alt_date'] : $p['date'];
            JobMove::to($job, $date, $start, $end, ['by' => 'client_text']);
            $this->send($job, $p, 'confirmed', 'You are confirmed for '.self::day($date).' between '.($alt ? $p['alt_slot'] : $p['slot']).'. See you then!');
        } elseif (! $alt) {
            $this->send($job, $p, 'alt_offered', $this->ask($p['alt_date'], $p['alt_slot']));
        } else {
            $this->send($job, $p, 'pm_call', 'No problem. Your project manager will call you to find a time that works.');
            $pm = User::query()->find($p['by'] ?? null);
            app(NotificationDispatcher::class)->dispatch(
                title: 'Call the client to schedule '.$job->number,
                body: 'They said no to both times offered by text.',
                subject: $job, onlyTo: $pm, urgent: true,
            );
        }

        return true;
    }

    /**
     * "8 - 11 AM", "11 AM - 2 PM", "2:30-5pm" as 24-hour HH:MM; a slot that
     * does not read as a window is the working day.
     *
     * @return array{0: string, 1: string}
     */
    public static function window(string $slot): array
    {
        if (! preg_match('/(\d{1,2})(?::(\d\d))?\s*(am|pm)?\s*[-–]\s*(\d{1,2})(?::(\d\d))?\s*(am|pm)/i', $slot, $m)) {
            return ['08:00', '17:00'];
        }
        $endPm = strtolower($m[6]) === 'pm';
        // A start with no AM/PM takes the end's, unless that puts it after the end (11 - 2 PM).
        $startPm = $m[3] !== '' ? strtolower($m[3]) === 'pm' : ($endPm && ((int) $m[1] % 12) <= ((int) $m[4] % 12));
        $h = fn (string $hour, bool $pm) => ((int) $hour % 12) + ($pm ? 12 : 0);

        return [sprintf('%02d:%02d', $h($m[1], $startPm), (int) ($m[2] ?: 0)), sprintf('%02d:%02d', $h($m[4], $endPm), (int) ($m[5] ?: 0))];
    }

    private function ask(string $date, string $slot): string
    {
        return 'We can schedule your job on '.self::day($date).' between '.$slot.'. Reply YES to confirm or NO for another time.';
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('l, M j');
    }

    private function send(Job $job, array $proposal, string $stage, string $body): ?string
    {
        try {
            $to = (string) ($job->customer?->phone ?? '');
            throw_if($to === '', RuntimeException::class, 'The client has no phone number to text.');
            $line = $this->line($job) ?? throw new RuntimeException('No texting line is cleared to send for this job\'s company.');
            $this->texts->send($line, $to, $body, automated: true);
        } catch (Throwable $e) {
            $this->note($job, 'Scheduling text not sent: '.$e->getMessage());

            return $e->getMessage();
        }
        $job->forceFill(['schedule_proposal' => ['stage' => $stage] + $proposal])->save();
        $this->note($job, 'Text to client: '.$body);

        return null;
    }

    private function note(Job $job, string $body): void
    {
        JobNote::query()->create(['organization_id' => $job->organization_id, 'job_id' => $job->id, 'body' => $body]);
    }

    /** The franchise's cleared line, then the company's, then the organization's. */
    private function line(Job $job): ?PhoneNumber
    {
        $cleared = PhoneNumber::query()->where('sms_outbound_allowed', true)->get();
        $companyId = $job->franchise?->company_id;

        return $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_FRANCHISE && $n->scope_id === $job->franchise_id)
            ?? $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_COMPANY && $companyId !== null && $n->scope_id === $companyId)
            ?? $cleared->first(fn ($n) => $n->scope_type === PhoneNumber::SCOPE_ORGANIZATION);
    }
}
