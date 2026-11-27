<?php

namespace App\Support\Tasks\Monday;

use RuntimeException;

/** Monday refused the token itself: the connection needs connecting again. */
final class MondayRefused extends RuntimeException
{
    public const RECONNECT = 'Monday no longer accepts this connection. Reconnect Monday.';
}
