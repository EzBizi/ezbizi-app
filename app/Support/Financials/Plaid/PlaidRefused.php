<?php

namespace App\Support\Financials\Plaid;

/** The bank login has to be redone (RELINK) or connected again from scratch (GONE). Shown on the card. */
final class PlaidRefused extends \RuntimeException
{
    public const RELINK = 'The bank asks you to sign in again. Press Reconnect on this bank in Financials › Bank.';

    public const GONE = 'This bank connection was removed at the bank or at Plaid. Connect the bank again.';

    public function __construct(string $message, public readonly string $plaidCode = '')
    {
        parent::__construct($message);
    }
}
