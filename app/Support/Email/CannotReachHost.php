<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A host was refused before anything connected to it (see {@see RemoteHostGuard}).
 *
 * Thrown rather than returned, for the reason CannotSendMail gives about
 * sending: a boolean is ignorable, and the one outcome that must never be
 * ignored is "we did not dial that, and here is why". A caller that forgets to
 * check a bool would have connected.
 *
 * The message is written for the administrator filling in the form. It never
 * says which private range was hit — that answer belongs to whoever is probing,
 * not to the person who mistyped their mail server.
 */
class CannotReachHost extends RuntimeException
{
    /**
     * 422, not 403.
     *
     * Nobody is being denied anything: the person asking may be the
     * organization's administrator with every permission there is. What is wrong
     * is the VALUE in the host field, which is a fact about the request rather
     * than about them — and a 403 would send them looking for a grant that would
     * change nothing.
     */
    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
