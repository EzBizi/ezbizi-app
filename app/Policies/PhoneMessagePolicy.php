<?php

namespace App\Policies;

use App\Models\PhoneMessage;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Auth\Access\Response;

/**
 * SMS from the Call desk: phone.view. Rows are filtered to granted numbers.
 */
class PhoneMessagePolicy
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

    public function create(User $user): Response
    {
        return $user->hasPermission('phone.view')
            ? Response::allow()
            : Refusal::permission('send a text from Phone');
    }

    public function view(User $user, PhoneMessage $message): Response
    {
        if ($message->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('phone.view')) {
            return Refusal::permission('open Phone');
        }

        $number = $message->number;
        if ($number === null) {
            return Response::denyAsNotFound();
        }

        return $this->access->mayView($user, $number)
            ? Response::allow()
            : Refusal::permission('open that phone line');
    }
}
