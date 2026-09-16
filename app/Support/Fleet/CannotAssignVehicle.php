<?php

namespace App\Support\Fleet;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The keys cannot go to this person, or cannot be taken back.
 *
 * Its message is written to be READ — by somebody on the vehicle screen, and
 * by somebody reading why an automation rule did nothing. Those are the same
 * sentence because they are the same fact, which is the point of there being
 * one assignment implementation rather than two.
 *
 * Not a permission refusal: `fleet_management.assign` is checked by the policy
 * before this is reached, and {@see \App\Support\Access\Refusal} carries that
 * sentence. This one is about the vehicle and the person.
 */
final class CannotAssignVehicle extends RuntimeException
{
    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
