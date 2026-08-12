<?php

namespace App\Support\Mail;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A message was not sent, and the reason is a sentence somebody can act on (R8).
 *
 * Thrown rather than returned, deliberately. A `bool` from a send call is
 * ignorable, and the one thing that must never happen quietly is mail failing to
 * go out under a tenant's own address while the screen says it went. Whoever
 * catches this has the sentence to show; whoever does not gets a 422 carrying
 * it, which is still the truth.
 *
 * The message is written for the person who will read it, never for us: no
 * permission key, no class name, no state constant — see SendingState::refusal,
 * which is where most of these sentences come from.
 */
class CannotSendMail extends RuntimeException
{
    public function __construct(
        string $refusal,
        public readonly ?MailIdentity $identity = null,
    ) {
        parent::__construct($refusal);
    }

    /**
     * 422 rather than 403.
     *
     * Nobody is being denied anything: the person asking may well have every
     * permission there is. What is wrong is the state of the identity they asked
     * to send as, which is a fact about the request's subject and not about
     * them. A 403 would send them to an administrator for a grant that would
     * change nothing.
     */
    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
