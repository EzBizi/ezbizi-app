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
    public const KINDS = ['project_link', 'scheduled', 'day_before', 'complete'];

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
        // Claimed first, so two sweeps cannot both send it; released on failure.
        try {
            $claim = ProjectBoardMessage::query()->create(['job_id' => $job->id, 'kind' => $kind]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        $body = $this->fill($job, $text);
        try {
            $to = (string) ($job->customer?->phone ?? '');
            $line = $this->schedule->line($job);
            if ($to === '' || $line === null) {
                throw new \RuntimeException($to === '' ? 'The client has no phone number.' : 'No texting line is cleared for this company.');
            }
            $this->texts->send($line, $to, $body, automated: true);
        } catch (Throwable $e) {
            $claim->delete();
            $this->note($job, "Client text ({$kind}) not sent: ".$e->getMessage());

            return $e->getMessage();
        }
        $this->note($job, 'Text to client: '.$body);
        if ($kind === 'scheduled') {
            $this->toCrew($job);
        }

        return null;
    }

    /**
     * The work order to the job's repair crew or contractor (Project
     * Management's repair-team list): a user gets an in-app notification, a
     * phone-only contractor a link to the PDF that lapses in 7 days.
     */
    private function toCrew(Job $job): void
    {
        $tag = collect($job->tags ?? [])->first(fn ($t) => preg_match('/^(REPAIR TEAM|CONTRACTOR)/i', (string) $t));
        if ($tag === null) {
            return;
        }
        $team = collect((array) $this->settings->get('project_board.repair_tags', $job->franchise?->company ?? $job->organization))
            ->first(fn ($e) => is_array($e) && strcasecmp((string) ($e['name'] ?? ''), (string) $tag) === 0);
        $when = $job->scheduled_for?->format('D M j, g:i A') ?? 'the scheduled date';
        $link = ProjectBoardController::workOrderLink($job);
        if (filled($team['user_id'] ?? null) && ($user = User::query()->find($team['user_id'])) !== null) {
            $this->notify->dispatch(title: "Work order: {$job->number} on {$when}", body: $job->customer?->name, subject: $job, onlyTo: $user);
        } elseif (filled($team['phone'] ?? null) && ($line = $this->schedule->line($job)) !== null) {
            try {
                $this->texts->send($line, (string) $team['phone'], "Work order for {$job->number} on {$when}: {$link}", automated: true);
            } catch (Throwable $e) {
                $this->note($job, 'Work order not texted to the crew: '.$e->getMessage());

                return;
            }
        }
        $this->note($job, "Work order sent to {$tag}.");
    }

    /** {client_first}, {company}, {date}, {link} in the company's wording. */
    public function fill(Job $job, string $text): string
    {
        return strtr($text, [
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
