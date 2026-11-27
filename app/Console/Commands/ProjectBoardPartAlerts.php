<?php

namespace App\Console\Commands;

use App\Models\JobPart;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Project Management's part alerts (Jose, 2026-11-17): a part ordered and
 * not received for three days "should turn red (like on leads) and send
 * notifications", and a reminder set on a part is sent when it is due. Each
 * goes once, to the people who can open Project Management and reach the
 * job, and each kind can be switched off in the bell's settings.
 */
class ProjectBoardPartAlerts extends Command
{
    protected $signature = 'ezbizi:project-board-part-alerts';

    protected $description = 'Tell Project Management about parts three days late and part reminders that are due';

    public const KIND_LATE = 'project_board.part_late';

    public const KIND_REMINDER = 'project_board.part_reminder';

    public function handle(TenantContext $tenant, NotificationDispatcher $notify): int
    {
        $sent = 0;
        $due = JobPart::query()->withoutGlobalScopes()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $late) => $late->where('kind', '!=', 'stock')->whereNull('received_at')
                    ->whereNull('late_notified_at')->where('ordered_at', '<=', now()->subDays(JobPart::LATE_AFTER_DAYS)))
                ->orWhere(fn (Builder $remind) => $remind->whereNull('reminded_at')->where('remind_at', '<=', now())))
            ->pluck('organization_id')->unique();

        foreach (Organization::query()->whereIn('id', $due)->get() as $organization) {
            $sent += $tenant->run($organization, fn () => $this->organization($notify));
        }

        $this->info("Sent {$sent} part alerts.");

        return self::SUCCESS;
    }

    private function organization(NotificationDispatcher $notify): int
    {
        $sent = 0;
        $people = User::query()->where('is_active', true)->get()
            ->filter(fn (User $u) => $u->hasPermission('project_board.view'))
            ->map(fn (User $u) => [$u, ViewScope::forUser($u)->franchiseIds()]);

        $parts = JobPart::query()->with('job.customer:id,name')
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $late) => $late->where('kind', '!=', 'stock')->whereNull('received_at')
                    ->whereNull('late_notified_at')->where('ordered_at', '<=', now()->subDays(JobPart::LATE_AFTER_DAYS)))
                ->orWhere(fn (Builder $remind) => $remind->whereNull('reminded_at')->where('remind_at', '<=', now())))
            ->get();

        foreach ($parts as $part) {
            $job = $part->job;
            if ($job === null) {
                continue;
            }
            $where = trim(($job->number ?? '').' · '.($job->customer?->name ?? ''), ' ·');
            $tell = [];
            if ($part->late_notified_at === null && $part->isLate()) {
                $tell[] = [self::KIND_LATE, "\"{$part->item}\" is 3 days late ({$where})",
                    'Ordered '.$part->ordered_at->format('M j').' and not received yet.'];
                $part->late_notified_at = now();
            }
            if ($part->reminded_at === null && $part->remind_at !== null && $part->remind_at->lte(now())) {
                $tell[] = [self::KIND_REMINDER, "Reminder: \"{$part->item}\" ({$where})", $part->remind_note];
                $part->reminded_at = now();
            }
            foreach ($tell as [$kind, $title, $body]) {
                foreach ($people as [$person, $reach]) {
                    if (in_array($job->franchise_id, $reach, true)) {
                        $sent += $notify->dispatch(title: $title, body: $body, subject: $part, onlyTo: $person, kind: $kind) > 0 ? 1 : 0;
                    }
                }
            }
            // Marked even with nobody to tell, so a part is never sent twice.
            $part->save();
        }

        return $sent;
    }
}
