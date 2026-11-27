<?php

namespace App\Support\ProjectBoard;

use App\Http\Controllers\Api\ProjectBoardController;
use App\Models\Job;
use App\Models\JobNote;
use App\Models\ProjectBoardMessage;
use App\Models\User;
use App\Support\Jobs\ScheduleTexts;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Phone\PhoneTexts;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Project Management's texts to the client (Jose, 2026-11-17), each its own
 * switch and wording in project_board.client_messages, every one OFF until
 * Jose approves the wording:
 *
 *   project_link  the job reaches the board: "see your project" + timeline link
 *   scheduled     it gets a date: the client, and the work order to the crew
 *   day_before    the day before the date: "happy to remind you…"
 *   complete      it is done: thank you
 *
 * Replies land in Project Management › Inbox. Each is sent once per job and
 * kind, from the job's branch line, and noted on the job.
 */
final class ClientMessages
{
    public const KINDS = ['project_link', 'scheduled', 'day_before', 'complete', 'three_day_before', 'crew_day_before'];

    /** The reminders that belong to one scheduled date. */
    private const DATED = ['day_before', 'three_day_before', 'crew_day_before'];

    /** Kept for a later retry; the job's note only on the first failure. */
    private function failed(ProjectBoardMessage $claim, Job $job, string $note): void
    {
        $claim->update(['failed_at' => now(), 'failures' => $claim->failures + 1]);
        if ($claim->failures === 1) {
            $this->note($job, $note);
        }
    }

    public function __construct(
        private readonly PhoneTexts $texts,
        private readonly ScheduleTexts $schedule,
        private readonly SettingsResolver $settings,
        private readonly NotificationDispatcher $notify,
    ) {}

    /** The company's switch and wording for [kind], or null when off. */
    public function wording(Job $job, string $kind): ?string
    {
        $all = (array) $this->settings->get('project_board.client_messages', $job->franchise?->company ?? $job->organization);
        $one = (array) ($all[$kind] ?? []);

        return ($one['on'] ?? false) === true && filled($one['text'] ?? null) ? (string) $one['text'] : null;
    }

    /**
     * Sends [kind] to [job]'s client when it is switched on and was not sent
     * before. Null when sent or off, else why it could not go.
     */
    public function send(Job $job, string $kind): ?string
    {
        $text = $this->wording($job, $kind);
        if ($text === null) {
            return null;
        }
        // Claimed first, so two sweeps cannot both send it. A reminder is
        // claimed per scheduled date (a rescheduled job is reminded again); a
        // failed send keeps its claim and is retried with backoff, its note
        // written once (audit 2026-11-27).
        $forDate = in_array($kind, self::DATED, true) ? (string) $job->scheduled_for?->toDateString() : '';
        $claim = ProjectBoardMessage::query()->where(['job_id' => $job->id, 'kind' => $kind, 'for_date' => $forDate])->first();
        if ($claim !== null && ($claim->failed_at === null
            || $claim->failed_at->gt(now()->subMinutes(min(1440, 15 * 2 ** min($claim->failures, 7)))))) {
            return null;
        }
        try {
            $claim ??= ProjectBoardMessage::query()->create(['job_id' => $job->id, 'kind' => $kind, 'for_date' => $forDate]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $body = $this->fill($job, $text);
        if ($kind === 'crew_day_before') {
            // The crew's work order, the day before (Jose's PM flow, 2026-11-23).
            if (($why = $this->toCrew($job, $body)) !== null) {
                $this->failed($claim, $job, "Work order not sent to the crew: {$why}");

                return $why;
            }
            $claim->update(['failed_at' => null]);

            return null;
        }
        try {
            $to = (string) ($job->customer?->phone ?? '');
            $line = $this->schedule->line($job);
            if ($to === '' || $line === null) {
                throw new \RuntimeException($to === '' ? 'The client has no phone number.' : 'No texting line is cleared for this company.');
            }
            $this->texts->send($line, $to, $body, automated: true);
        } catch (Throwable $e) {
            $this->failed($claim, $job, "Client text ({$kind}) not sent: ".$e->getMessage());

            return $e->getMessage();
        }
        $claim->update(['failed_at' => null]);
        $this->note($job, 'Text to client: '.$body);
        // At scheduling, as before, while the day-before crew text is off;
        // never both (desk, 2026-11-23).
        if ($kind === 'scheduled' && $this->wording($job, 'crew_day_before') === null
            && collect($job->tags ?? [])->contains(fn ($t) => ProjectBoardController::isRepairTeam((string) $t))) {
            $when = $job->scheduled_for?->format('D M j, g:i A') ?? 'the scheduled date';
            if (($why = $this->toCrew($job, "Work order for {$job->number} on {$when}: ".ProjectBoardController::workOrderLink($job))) !== null) {
                $this->note($job, "Work order not sent to the crew: {$why}");
            }
        }

        return null;
    }

    /**
     * The work order to the job's repair crew or contractor (Project
     * Management's repair-team list): a user gets an in-app notification, a
     * phone-only contractor a link to the PDF that lapses in 7 days.
     */
    private function toCrew(Job $job, string $body): ?string
    {
        // Any repair-team or contractor tag, as the board reads them ("KENNI REPAIR TEAM" too).
        $tag = collect($job->tags ?? [])->first(fn ($t) => ProjectBoardController::isRepairTeam((string) $t));
        if ($tag === null) {
            return 'No repair team or contractor on the job.';
        }
        $team = collect((array) $this->settings->get('project_board.repair_tags', $job->franchise?->company ?? $job->organization))
            ->first(fn ($e) => is_array($e) && strcasecmp((string) ($e['name'] ?? ''), (string) $tag) === 0);
        $when = $job->scheduled_for?->format('D M j, g:i A') ?? 'the scheduled date';
        if (filled($team['user_id'] ?? null) && ($user = User::query()->find($team['user_id'])) !== null) {
            $this->notify->dispatch(title: "Work order: {$job->number} on {$when}", body: $body, subject: $job, onlyTo: $user);
        } elseif (filled($team['phone'] ?? null) && ($line = $this->schedule->line($job)) !== null) {
            try {
                $this->texts->send($line, (string) $team['phone'], $body, automated: true);
            } catch (Throwable $e) {
                $this->note($job, 'Work order not texted to the crew: '.$e->getMessage());

                return $e->getMessage();
            }
        } else {
            return "{$tag} has no user or phone in the repair team list.";
        }
        $this->note($job, "Work order sent to {$tag}: {$body}");

        return null;
    }

    /**
     * Jose's PM flow (2026-11-23): 3 days before the job, when parts were
     * ordered and are not received, the project manager is asked to confirm
     * them. Once per job; the PM who readied it, else Project Management's
     * people who reach the job.
     */
    public function partsCheck(Job $job): void
    {
        $open = \App\Models\JobPart::query()->where('job_id', $job->id)->where('kind', '!=', 'stock')->whereNull('received_at')->pluck('item');
        if ($open->isEmpty()) {
            return;
        }
        try {
            ProjectBoardMessage::query()->create(['job_id' => $job->id, 'kind' => 'pm_parts_check']);
        } catch (UniqueConstraintViolationException) {
            return;
        }
        $pm = User::query()->find(((array) $job->schedule_proposal)['by'] ?? null);
        $people = $pm ? collect([$pm]) : User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('project_board.view')
                && in_array($job->franchise_id, \App\Support\Scoping\ViewScope::forUser($u)->franchiseIds(), true));
        foreach ($people as $person) {
            $this->notify->dispatch(title: "Confirm parts are in for {$job->number}", body: 'Scheduled in 3 days; not received yet: '.$open->implode(', '),
                subject: $job, onlyTo: $person, urgent: true);
        }
    }

    /** {client_first}, {company}, {date}, {link} in the company's wording. */
    public function fill(Job $job, string $text): string
    {
        $pm = User::query()->find(((array) $job->schedule_proposal)['by'] ?? null);
        $due = (int) (\App\Support\Jobs\JobMoney::of([$job->id])[$job->id]['due'] ?? 0);

        return strtr($text, [
            // The project manager: who sent it to Ready to schedule; else the branch line.
            '{pm_name}' => $pm?->name ?? 'your project manager',
            '{pm_phone}' => (string) ($pm?->phone ?: $this->schedule->line($job)?->e164 ?? ''),
            '{job}' => (string) $job->number,
            '{balance}' => '$'.number_format($due / 100, 2),
            '{work_order}' => ProjectBoardController::workOrderLink($job),
            '{client_first}' => trim(strtok((string) $job->customer?->name, ' ') ?: 'there'),
            '{company}' => (string) ($job->franchise?->company?->name ?? $job->franchise?->name ?? ''),
            '{date}' => $job->scheduled_for?->format('l, F j \a\t g:i A') ?? '',
            '{link}' => self::timelineLink($job),
        ]);
    }

    /** The client's read-only page for their project; lapses in 60 days. */
    public static function timelineLink(Job $job): string
    {
        return URL::temporarySignedRoute('project.timeline', now()->addDays(60), ['job' => $job->id]);
    }

    private function note(Job $job, string $body): void
    {
        JobNote::create(['job_id' => $job->id, 'user_id' => null, 'body' => $body]);
    }
}
