<?php

namespace App\Policies;

use App\Models\PhoneCallTag;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may change the list of call tags, and who may use it.
 *
 * The split is the point. Tagging a call is done by whoever answered it —
 * usually somebody with `phone.view` and a line grant and nothing else. Editing
 * the LIST changes what every report in the organization counts by, so it needs
 * `phone.edit` and authority over the organization.
 *
 * Borrowing PhoneNumberPolicy for this was wrong in a way worth recording: its
 * create() denies an organization scope as NOT FOUND, so every attempt to make
 * a tag answered 404 — a missing-page error for a permissions question.
 */
class PhoneCallTagPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('open Phone');
    }

    /** Putting a tag on a call — deliberately only view. */
    public function tag(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('tag a call');
    }

    public function manage(User $user): Response
    {
        if (! $user->hasPermission('phone.edit')) {
            return Refusal::permission('change the call tags');
        }

        return $user->canActOnOrganization($user->organization)
            ? Response::allow()
            : Refusal::abovePosition('change the call tags for the organization');
    }

    public function create(User $user): Response
    {
        return $this->manage($user);
    }

    public function update(User $user, PhoneCallTag $tag): Response
    {
        return $this->manage($user);
    }

    public function delete(User $user, PhoneCallTag $tag): Response
    {
        return $this->manage($user);
    }
}
