<?php

namespace App\Support\Fleet;

use App\Models\User;

/**
 * Money attributes on fleet payloads (spec §8).
 *
 * Without `fleet_management.financials`, every read path omits amounts and the
 * payments relation. Controllers call [strip] on presented arrays rather than
 * branching per field.
 */
final class VehicleFinancials
{
    /**
     * Every money attribute name — cents columns plus terms that reveal the deal.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            'purchase_price_cents',
            'lienholder',
            'loan_account',
            'loan_monthly_payment_cents',
            'loan_payoff_date',
            'transport_cost_cents',
            'rental_rate_cents',
            'rental_rate_period',
            'rental_deposit_cents',
            'rental_account_number',
            'rental_payment_terms',
            'rental_payment_method',
            'premium_cents',
            'premium_period',
            'amount_cents',
        ];
    }

    /**
     * Drop money keys and `payments` when the caller lacks financials.
     *
     * Walks nested arrays so ownership / insurance ledgers strip the same way.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function strip(array $payload, User $user): array
    {
        if ($user->hasPermission('fleet_management.financials')) {
            return $payload;
        }

        return self::stripKeys($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stripKeys(array $payload): array
    {
        unset($payload['payments']);

        foreach (self::keys() as $key) {
            unset($payload[$key]);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                // List of associative rows (e.g. ownership history) or one nested map.
                $payload[$key] = array_is_list($value)
                    ? array_map(
                        fn ($row) => is_array($row) ? self::stripKeys($row) : $row,
                        $value,
                    )
                    : self::stripKeys($value);
            }
        }

        return $payload;
    }
}
