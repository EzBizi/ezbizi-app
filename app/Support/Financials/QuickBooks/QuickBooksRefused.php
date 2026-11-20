<?php

namespace App\Support\Financials\QuickBooks;

use RuntimeException;

/** QuickBooks refused the token itself: the connection needs connecting again. */
final class QuickBooksRefused extends RuntimeException
{
    public const RECONNECT = 'QuickBooks no longer accepts this connection. Reconnect QuickBooks.';
}
