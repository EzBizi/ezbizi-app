<?php

namespace App\Support\Email;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A write against the mailbox that this connection cannot do.
 *
 * Separate from {@see CannotReadMailbox}: failing to bin a message is not
 * "we cannot look", and must not mark the inbox broken or empty the list.
 */
final class CannotChangeMail extends RuntimeException
{
    public function status(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
