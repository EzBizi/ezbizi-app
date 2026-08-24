<?php

namespace App\Policies;

use App\Models\PhoneCall;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Auth\Access\Response;

/**
 * Records list: phone.view. Rows themselves are filtered to granted numbers.
 */
class PhoneCallPolicy
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

    public function view(User $user, PhoneCall $call): Response
    {
        if ($call->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.view')) {
            return Refusal::permission('open Phone');
        }

        $number = $call->number;
        if ($number === null) {
            return Response::denyAsNotFound();
        }

        return $this->access->mayView($user, $number)
            ? Response::allow()
            : Refusal::permission('open that phone line');
    }
}
