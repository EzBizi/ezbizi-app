<?php

namespace App\Http\Controllers\Api\Phone\Concerns;

use App\Models\PhoneConnection;
use App\Models\User;
use App\Policies\Concerns\ScopedToPhoneConnection;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Which Twilio connections a caller may read, and tenant-safe exists rules.
 *
 * The read side is the query mirror of
 * {@see ScopedToPhoneConnection}: the policy answers
 * "may this person touch THIS one", this answers "which ones may they see at
 * all". They have to agree, so they are named after each other on purpose — a
 * list that showed a row the policy then refused would be a screen offering a
 * control that does not work (§4).
 *
 * Written out identically in HoursController before ring groups needed it. A
 * third copy is where a scoping rule starts drifting between endpoints, and a
 * scoping rule that drifts is a tenant leak with a changelog.
 */
trait ResolvesPhoneConnections
{
    /** @return Collection<int, PhoneConnection> */
    protected function connectionsInReach(User $user): Collection
    {
        // An organization assignment sees every connection in the tenant,
        // including each company's. Authority flows DOWN, which is the
        // direction §3 permits.
        if ($user->canActOnOrganization($user->organization)) {
            return PhoneConnection::query()->get();
        }

        $companyIds = $user->reachableCompanies()->pluck('id')->all();

        // Deliberately excludes the organization connection: a company caller
        // may use credentials that walk up to it, but may not read or edit the
        // organization's own row. Authority does not flow up.
        return PhoneConnection::query()
            ->where('scope_type', PhoneConnection::SCOPE_COMPANY)
            ->whereIn('scope_id', $companyIds)
            ->get();
    }

    protected function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
