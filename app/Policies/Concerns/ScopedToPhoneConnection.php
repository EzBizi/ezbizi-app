<?php

namespace App\Policies\Concerns;

use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Reach on the Twilio connection a phone record hangs off (spec §4, §12).
 *
 * ── Why this is a trait and not a fifth copy ─────────────────────
 *
 * Everything under Phone that is not a call or a message belongs to a
 * connection — hours schedules, ring groups, flows — and "may this person edit
 * it" is the same question for all of them: the organization connection needs
 * authority over the organization, a company connection needs reach on that
 * company, and anything belonging to another tenant is not found rather than
 * refused.
 *
 * That block was written out in full in PhoneHoursSchedulePolicy and was about
 * to be written out again, unchanged, for ring groups. Six policies elsewhere
 * in this codebase already carry a byte-identical copy of the equivalent
 * company-scoping rule, and the cost of that is not the lines: it is that a
 * correction has to be found in six places, and the sixth is the one somebody
 * misses.
 *
 * @see \App\Policies\PhoneHoursSchedulePolicy
 * @see \App\Policies\PhoneRingGroupPolicy
 */
trait ScopedToPhoneConnection
{
    /**
     * @param  string  $actFor  the verb phrase a refusal completes, e.g.
     *                          "edit ring groups for" — the sentence the client
     *                          shows comes from here, never from the client.
     */
    protected function connectionReach(User $user, ?PhoneConnection $connection, string $actFor): Response
    {
        // Not-found rather than forbidden for another tenant's row: a refusal
        // sentence would confirm the id exists.
        if ($connection === null || $connection->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if ($connection->scope_type === PhoneConnection::SCOPE_ORGANIZATION) {
            return $user->canActOnOrganization($user->organization)
                ? Response::allow()
                : Refusal::abovePosition($actFor.' the organization');
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
                $actFor.' this company',
                $company->name,
            );
    }
}
