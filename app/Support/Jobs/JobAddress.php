<?php

namespace App\Support\Jobs;

use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * A job's street line must start with a house number (dispatchers, 2026-11-17:
 * "at least 1-2 jobs per day ... missing house numbers"; 121 of 8,584 Workiz
 * jobs in 30 days). "N1234 County Rd" (Wisconsin grid) and PO boxes pass.
 */
final class JobAddress
{
    public static function hasHouseNumber(?string $line1): bool
    {
        return (bool) preg_match('/^\s*([A-Z]?\d|p\.?\s*o\.?\s*box)/i', (string) $line1);
    }

    /** What is wrong with a street + postal code, as short words; [] when fine. */
    public static function problems(?string $line1, ?string $postal): array
    {
        return array_values(array_filter([
            blank($line1) ? 'no street' : (self::hasHouseNumber($line1) ? null : 'no house number'),
            blank($postal) ? 'no postal code' : null,
        ]));
    }

    /**
     * Refuses a booking whose street has no house number. A blank street is
     * allowed: a job can be booked before anyone has the address.
     */
    public static function assertBookable(?string $line1, string $field = 'address_line1'): void
    {
        if (filled($line1) && ! self::hasHouseNumber($line1)) {
            throw ValidationException::withMessages([$field => 'The address "'.trim($line1).'" has no house number. Add it (e.g. "12 '.trim($line1).'") before booking.']);
        }
    }

    public static function assertCustomer(Customer $customer): void
    {
        self::assertBookable($customer->address_line1);
    }
}
