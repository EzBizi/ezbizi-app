<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Role;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Monday's two invitation notices (Jose, 2026-11-25, full parity):
 *   user.not_signed_up   — to the inviter, when an invitation sent 7 days ago
 *                          is still not accepted;
 *   user.invite_pending  — to administrators, when an invitation has been
 *                          held for approval 7 days.
 *
 * Run once a day over the day that turned 7 days old, so each invitation is
 * told about exactly once without a column to remember it.
 */
class NotifyInvitationFollowUps extends Command
{
    protected $signature = 'ezbizi:notify-invitation-follow-ups';

    protected $description = 'Tell inviters and admins about invitations untouched for 7 days';

    public function handle(TenantContext $tenant, NotificationDispatcher $dispatcher): int
    {
        $from = now()->subDays(8);
        $to = now()->subDays(7);
        $told = 0;

        foreach (Organization::query()->get() as $organization) {
            $told += $tenant->run($organization, function () use ($dispatcher, $from, $to) {
                $told = 0;

                $unanswered = Invitation::query()
                    ->whereBetween('sent_at', [$from, $to])
                    ->whereNull('accepted_at')->whereNull('revoked_at')
                    ->whereNotNull('invited_by_user_id')
                    ->with('invitedBy')->get();
                foreach ($unanswered as $invitation) {
                    // A null onlyTo would mean "everyone"; an inviter who has
                    // since gone is nobody to tell.
                    if ($invitation->invitedBy === null) {
                        continue;
                    }
                    $told += $dispatcher->dispatch(
                        title: $invitation->email.' has not signed up',
                        body: 'You invited them a week ago.',
                        onlyTo: $invitation->invitedBy,
                        source: 'system',
                        kind: 'user.not_signed_up',
                    );
                }

                $held = Invitation::query()
                    ->whereBetween('held_at', [$from, $to])
                    ->whereNull('sent_at')->whereNull('revoked_at')->count();
                if ($held > 0) {
                    $admins = Role::query()->where('name', 'Administrator')->pluck('id')->all();
                    $told += $dispatcher->dispatch(
                        title: $held === 1 ? '1 invite request is waiting' : "{$held} invite requests are waiting",
                        body: 'Pending approval for over 7 days.',
                        source: 'system',
                        roleIds: $admins,
                        kind: 'user.invite_pending',
                    );
                }

                return $told;
            });
        }

        $this->info("Told {$told}.");

        return self::SUCCESS;
    }
}
