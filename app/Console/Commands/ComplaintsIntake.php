<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Models\Complaint;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Review;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Opens a complaint for each new bad review, Bad Rating and complaint call
 * (Jose, 2026-10-20), by reading the events their modules already raise -
 * review.received of 3 stars or fewer, lead.received for a Bad Rating form,
 * lead.call_complaint - so no other module is changed to feed it.
 *
 * Every minute from where the last run stopped; --since=Y-m-d is the backfill.
 * One complaint per source and kind, so re-reading an event opens nothing new.
 * Each one opened live raises complaint.opened for rules; a backfill raises nothing.
 */
class ComplaintsIntake extends Command
{
    protected $signature = 'ezbizi:complaints-intake {--since= : read events from this date (a backfill)}';

    protected $description = 'Open complaints from bad reviews, Bad Ratings and complaint calls';

    private const CURSOR = 'complaints-intake:cursor';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $since = $this->option('since') ?: Cache::get(self::CURSOR, now()->subMinutes(10)->toDateTimeString());
        $until = now()->toDateTimeString();
        $opened = 0;

        AutomationEvent::withoutGlobalScopes()
            ->whereIn('event_key', ['review.received', 'lead.received', 'lead.call_complaint', 'email.received'])
            ->where('occurred_at', '>=', $since)->where('occurred_at', '<', $until)
            ->orderBy('occurred_at')
            ->each(function (AutomationEvent $event) use ($tenant, $events, &$opened) {
                $organization = Organization::withoutGlobalScopes()->find($event->organization_id);
                if ($organization === null) {
                    return;
                }
                $tenant->run($organization, function () use ($event, $events, &$opened) {
                    $complaint = $this->open($event);
                    if ($complaint?->wasRecentlyCreated) {
                        $opened++;
                        // A backfill is history, not news: no rule fires on it.
                        ! $this->option('since') && $events->emit('complaint.opened', $complaint, ['after' => $complaint->only(['kind', 'rating', 'raised_at', 'client_status', 'priority', 'due_at'])]);
                    }
                });
            });

        if ($this->option('since')) {
            // A profile's first import raises nothing (it is the backlog, not
            // news), so a backfill also reads the reviews themselves.
            Review::withoutGlobalScopes()->where('rating', '<=', 3)->where('reviewed_at', '>=', $since)
                ->whereNull('removed_at')->orderBy('reviewed_at')
                ->each(function (Review $review) use ($tenant, &$opened) {
                    $organization = Organization::withoutGlobalScopes()->find($review->organization_id);
                    $organization && $tenant->run($organization, function () use ($review, &$opened) {
                        $complaint = Complaint::query()->firstOrCreate(
                            ['source_type' => 'review', 'source_id' => $review->getKey(), 'kind' => 'bad_review'],
                            ['organization_id' => $review->organization_id, 'company_id' => $review->company_id,
                                'franchise_id' => $review->franchise_id, 'status' => 'new', 'rating' => $review->rating,
                                'raised_at' => $review->reviewed_at],
                        );
                        $opened += $complaint->wasRecentlyCreated ? 1 : 0;
                    });
                });
        } else {
            Cache::forever(self::CURSOR, $until);
        }
        $this->info("{$opened} complaint(s) opened.");

        return self::SUCCESS;
    }

    private function open(AutomationEvent $event): ?Complaint
    {
        if ($event->event_key === 'email.received') {
            return $this->fromEmail($event);
        }

        [$kind, $type, $subject] = match ($event->event_key) {
            'review.received' => ['bad_review', 'review', Review::query()->find($event->subject_id)],
            'lead.call_complaint' => ['call_complaint', 'lead', Lead::query()->find($event->subject_id)],
            default => ['bad_rating', 'lead', Lead::query()->find($event->subject_id)],
        };

        if ($subject === null
            || ($kind === 'bad_review' && ($subject->rating === null || $subject->rating > 3))
            || ($kind === 'bad_rating' && $subject->lead_type !== 'BAD_RATING')) {
            return null;
        }

        $complaint = Complaint::query()->firstOrNew(
            ['source_type' => $type, 'source_id' => $subject->getKey(), 'kind' => $kind],
            ['organization_id' => $event->organization_id, 'company_id' => $subject->company_id,
                'franchise_id' => $subject->franchise_id, 'status' => 'new',
                'rating' => $kind === 'bad_review' ? $subject->rating : null, 'raised_at' => $event->occurred_at],
        );
        if (! $complaint->exists) {
            \App\Support\CustomerExperience\ComplaintTriage::apply($complaint)->save();
        }

        return $complaint;
    }

    /**
     * BBB and Attorney General complaints arrive by mail (Shoval, 2026-11-20): neither has an API.
     * Mail from bbb.org, or from a .gov sender whose subject names the Attorney General or consumer
     * protection, opens a Priority complaint with the subject as its title.
     */
    private function fromEmail(AutomationEvent $event): ?Complaint
    {
        $after = (array) ($event->payload['after'] ?? []);
        $domain = strtolower((string) ($after['from_domain'] ?? substr(strrchr((string) ($after['from'] ?? ''), '@') ?: '', 1)));
        $subject = trim((string) ($after['subject'] ?? ''));
        $kind = match (true) {
            $domain === 'bbb.org' || str_ends_with($domain, '.bbb.org') => 'bbb',
            str_ends_with($domain, '.gov') && preg_match('/attorney general|consumer protection|consumer complaint/i', $subject) => 'attorney_general',
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $mailbox = \App\Models\Mailbox::query()->find($event->subject_id);
        $complaint = Complaint::query()->firstOrNew(
            ['source_type' => 'email', 'source_id' => $event->getKey(), 'kind' => $kind],
            ['organization_id' => $event->organization_id, 'company_id' => $mailbox?->company_id ?? null,
                'franchise_id' => $mailbox?->franchise_id ?? null, 'status' => 'new', 'raised_at' => $event->occurred_at,
                'title' => mb_substr($subject !== '' ? $subject : strtoupper($kind).' complaint', 0, 255),
                'summary' => 'From '.($after['from'] ?? $domain).' to '.($mailbox?->address ?? $mailbox?->email ?? 'a shared inbox').'.'],
        );
        if (! $complaint->exists) {
            \App\Support\CustomerExperience\ComplaintTriage::apply($complaint)->save();
        }

        return $complaint;
    }
}
