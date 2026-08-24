<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may connect or disconnect Twilio for Phone (spec §4–§5).
 *
 * Organization connection requires an organization assignment (same gate as
 * Settings). Company connection requires company write. Franchise cannot
 * connect — authority never flows upward.
 */
class PhoneConnectionPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    /**
     * @param  'organization'|'company'  $scopeType
     */
    public function create(User $user, string $scopeType, ?Company $company = null): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('connect Twilio');
        }

        if ($scopeType === PhoneConnection::SCOPE_ORGANIZATION) {
            return $user->canActOnOrganization($user->organization)
                ? Response::allow()
                : Refusal::abovePosition('connect Twilio for the organization');
        }

        if ($company === null || $company->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                'connect Twilio for this company',
                $company->name,
            );
    }

    public function delete(User $user, PhoneConnection $connection): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('disconnect Twilio');
        }

        if ($connection->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($connection->scope_type === PhoneConnection::SCOPE_ORGANIZATION) {
            return $user->canActOnOrganization($user->organization)
                ? Response::allow()
                : Refusal::abovePosition('disconnect Twilio for the organization');
        }

        $company = Company::find($connection->scope_id);
        if ($company === null || $company->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_COMPANY,
                'disconnect Twilio for this company',
                $company->name,
            );
    }
}
