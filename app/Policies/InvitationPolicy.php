<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;
use App\Support\Access\ScopeReach;
use Illuminate\Auth\Access\Response;

/**
 * Who may invite, chase and withdraw (§4, EZBIZI-HR-PLAN.md).
 *
 * Three actions on the module and only two permissions behind them, which is
 * deliberate: `hr.create` covers issuing an invitation AND everything that
 * happens to it afterwards. Resending and revoking are not smaller or larger
 * authorities than inviting — they are the same authority exercised again, and
 * the person who should be able to take an invitation back is the person who was
 * able to send it. Splitting them would produce a role that can create pending
 * invitations it is then unable to cancel.
 *
 * Reach applies to an EXISTING invitation the same way it applies to a person:
 * an invitation to a franchise you have no authority over is not yours to chase
 * or to withdraw. Refused as a 403, because it is the target that is off limits.
 * Whether the scope you are ASKING for is within reach is a different question
 * with a different answer — see ScopeReach::assertCovers, which is a 422,
 * because there the values are wrong rather than the target.
 */
class InvitationPolicy
{
    public function __construct(
        private readonly ScopeReach $reach,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('hr.view');
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $user->hasPermission('hr.view')
            && $this->withinReach($user, $invitation);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('hr.create');
    }

    /** Resending. Same authority as inviting — see the class docblock. */
    public function resend(User $user, Invitation $invitation): Response
    {
        return $this->mayManage($user, $invitation, 'resend');
    }

    /** Revoking. Also `hr.create`, and also for the reason above. */
    public function delete(User $user, Invitation $invitation): Response
    {
        return $this->mayManage($user, $invitation, 'withdraw');
    }

    private function mayManage(User $user, Invitation $invitation, string $verb): Response
    {
        if (! $user->hasPermission('hr.create')) {
            return Response::deny("You do not have permission to {$verb} invitations.");
        }

        if (! $this->withinReach($user, $invitation)) {
            return Response::deny(sprintf(
                'That invitation is for a place outside your reach, so it is not yours to %s. '
                .'Somebody with authority over it can.',
                $verb,
            ));
        }

        return Response::allow();
    }

    /**
     * The invitation's assigned scope, asked against the caller's authority.
     *
     * Whoever SENT it is not a second way in. An inviter whose reach has since
     * been narrowed should stop being able to act on what they sent from the old
     * position — otherwise a demotion leaves a trail of invitations the person
     * can still resend, which is a slower version of keeping the access.
     */
    private function withinReach(User $user, Invitation $invitation): bool
    {
        return $invitation->organization_id === $user->organization_id
            && $this->reach->covers($user, $invitation->scopeType(), $invitation->scopeId());
    }
}
