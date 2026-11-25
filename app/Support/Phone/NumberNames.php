<?php

namespace App\Support\Phone;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;

/**
 * Who is behind a phone number: a customer, else a lead, else a colleague
 * (Jose, 2026-10-09: Messages, Recent calls and Voicemail named nobody).
 *
 * Phones are stored as typed, mostly E.164 but some "(308) 555-0100", so a
 * number is compared on its last ten digits. Customers are asked for the
 * usual spellings in one query, leads by their `phone_digits`, and colleagues
 * are few enough per tenant to read whole. Runs inside the tenant, so every
 * query is scoped the way the lists themselves are.
 */
final class NumberNames
{
    public const CUSTOMER = 'customer';

    public const LEAD = 'lead';

    public const COLLEAGUE = 'colleague';

    /**
     * @param  iterable<string|null>  $numbers
     * @return array<string, array{name: string, kind: string, id: string}> keyed by the number as given
     */
    public function for(iterable $numbers): array
    {
        $byDigits = [];
        foreach ($numbers as $number) {
            $digits = self::lastTen((string) $number);
            if ($digits !== null) {
                $byDigits[$digits][] = (string) $number;
            }
        }
        if ($byDigits === []) {
            return [];
        }

        $tens = array_map('strval', array_keys($byDigits));
        $spellings = [];
        foreach ($tens as $d) {
            [$a, $b, $c] = [substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)];
            array_push($spellings, '+1'.$d, $d, '1'.$d, "($a) $b-$c", "$a-$b-$c", "$a.$b.$c", "+1 ($a) $b-$c", "$a $b $c");
        }

        // In order of precedence: the first kind to claim a number keeps it,
        // and within customers the oldest record, so duplicates resolve the
        // same way every time.
        $found = [];
        $claim = function (iterable $rows, string $kind, string $nameField, string $phoneField) use (&$found, $byDigits) {
            foreach ($rows as $row) {
                $digits = self::lastTen((string) $row->{$phoneField});
                $name = trim((string) $row->{$nameField});
                if ($digits === null || $name === '' || isset($found[$digits]) || ! isset($byDigits[$digits])) {
                    continue;
                }
                $found[$digits] = ['name' => $name, 'kind' => $kind, 'id' => (string) $row->id];
            }
        };

        $claim(Customer::query()->whereIn('phone', $spellings)->oldest()->get(['id', 'name', 'phone']), self::CUSTOMER, 'name', 'phone');
        $claim(
            Lead::query()
                ->whereIn('phone_digits', array_merge($tens, array_map(fn ($d) => '1'.$d, $tens)))
                ->where('is_test', false)
                ->latest('received_at')
                ->get(['id', 'contact_name', 'phone_digits']),
            self::LEAD, 'contact_name', 'phone_digits',
        );
        $claim(User::query()->whereNotNull('phone')->where('phone', '!=', '')->get(['id', 'name', 'phone']), self::COLLEAGUE, 'name', 'phone');

        $out = [];
        foreach ($found as $digits => $who) {
            foreach ($byDigits[$digits] as $number) {
                $out[$number] = $who;
            }
        }

        return $out;
    }

    /** The last ten digits, so "+1 (212) 555-1212" and "2125551212" agree, or null. */
    public static function lastTen(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen((string) $digits) >= 10 ? substr((string) $digits, -10) : null;
    }
}
