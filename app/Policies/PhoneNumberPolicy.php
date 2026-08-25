<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Auth\Access\Response;

/**
 * Who may list, buy, or release phone numbers (spec §4–§5, §12).
 *
 * Call/Records view is phone.view plus grant (Administrator: all in reach).
 * Settings list unions that with phone.edit ∩ reach (no grant). Buy and release
 * are phone.edit plus reach over the place the number hangs on.
 */
class PhoneNumberPolicy
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
    ) {}

    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    public function view(User $user, PhoneNumber $number): Response
    {
        if ($number->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $this->access->mayView($user, $number)
            ? Response::allow()
            : Refusal::permission('open that phone line');
    }

    /**
     * @param  'organization'|'company'|'franchise'  $scopeType
     */
    public function create(User $user, string $scopeType, ?string $scopeId = null): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('buy a phone number');
        }

        return $this->reachResponse($user, $scopeType, $scopeId, 'buy a phone number for');
    }

    public function delete(User $user, PhoneNumber $number): Response
    {
        if ($number->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('release this phone number');
        }

        return $this->reachResponse(
            $user,
            (string) $number->scope_type,
            (string) $number->scope_id,
            'release a phone number for',
        );
    }

    private function reachResponse(
        User $user,
        string $scopeType,
        ?string $scopeId,
        string $actFor,
    ): Response {
        if ($scopeId === null || $scopeId === '') {
            return Response::denyAsNotFound();
        }

        if ($scopeType === PhoneNumber::SCOPE_ORGANIZATION) {
            if ($scopeId !== $user->organization_id) {
                return Response::denyAsNotFound();
            }

            return $user->canActOnOrganization($user->organization)
                ? Response::allow()
                : Refusal::abovePosition($actFor.' the organization');
        }

        if ($scopeType === PhoneNumber::SCOPE_COMPANY) {
            $company = Company::find($scopeId);
            if ($company === null || $company->organization_id !== $user->organization_id) {
                return Response::denyAsNotFound();
            }

            return $user->canActOnCompany($company)
                ? Response::allow()
                : Refusal::noReach(
                    $user,
                    Refusal::AT_COMPANY,
                    $actFor.' this company',
                    $company->name,
                );
        }

        if ($scopeType === PhoneNumber::SCOPE_FRANCHISE) {
            $franchise = Franchise::find($scopeId);
            if ($franchise === null || $franchise->organization_id !== $user->organization_id) {
                return Response::denyAsNotFound();
            }

            return $user->canActOnFranchise($franchise)
                ? Response::allow()
                : Refusal::noReach(
                    $user,
                    Refusal::AT_FRANCHISE,
                    $actFor.' this '.$user->organization->franchise_label,
                    $franchise->name,
                );
        }

        return Response::denyAsNotFound();
    }
}
