<?php

namespace App\Support\Financials\Plaid;

/** A Plaid error that is not the bank login's: the next run tries again. */
final class PlaidError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $plaidCode = '')
    {
        parent::__construct($message);
    }
}
