<?php

namespace App\Support\Leads;

use App\Support\Phone\NumberNames;

/**
 * Is this number one of the business's customers? NumberNames' answer (any
 * spelling of the last ten digits), the one Phone uses for the caller's name.
 * A customer's call is never Possible spam and never Not a lead (Jose,
 * 2026-10-16).
 */
final class KnownCustomer
{
    public static function is(?string $phone): bool
    {
        if ($phone === null || $phone === '') {
            return false;
        }

        return (app(NumberNames::class)->for([$phone])[$phone]['kind'] ?? null) === NumberNames::CUSTOMER;
    }
}
