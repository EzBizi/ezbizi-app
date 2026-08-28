<?php

namespace App\Policies;

use App\Models\PhoneServiceRequest;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may ask EzBizi for phone service (§4 gate 2).
 *
 * Asking is an organization-level commercial act — it commits the organization
 * to a paid extra — so it needs authority over the organization, not merely
 * `phone.edit` at some company. A branch manager who can edit phone settings
 * for their own company still cannot sign the group up for a bill.
 *
 * Seeing the state is wider: anyone who can open Phone should be able to read
 * "we have asked, we are waiting", or the tab is a mystery.
 */
class PhoneServiceRequestPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    public function create(User $user): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('ask for phone service');
        }

        return $user->canActOnOrganization($user->organization)
            ? Response::allow()
            : Refusal::abovePosition('ask for phone service for the organization');
    }
}
