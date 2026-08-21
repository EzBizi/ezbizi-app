<?php

namespace App\Support\Fleet;

/**
 * Derived display name for a vehicle (spec §5). Never typed on the form —
 * rewritten whenever identity changes, stored on `vehicles.label` so list
 * search and sort have a stable column.
 *
 * Degrade: `{year} {make} {model} · {plate}` → description → plate → VIN →
 * Unidentified vehicle.
 */
final class VehicleName
{
    public static function derive(
        ?int $year,
        ?string $make,
        ?string $model,
        ?string $plate,
        ?string $vin,
    ): string {
        $make = self::present($make);
        $model = self::present($model);
        $plate = self::present($plate);
        $vin = self::present($vin);

        $parts = [];
        if ($year !== null) {
            $parts[] = (string) $year;
        }
        if ($make !== null) {
            $parts[] = $make;
        }
        if ($model !== null) {
            $parts[] = $model;
        }
        $description = $parts === [] ? null : implode(' ', $parts);

        if ($description !== null && $plate !== null) {
            return $description.' · '.$plate;
        }
        if ($description !== null) {
            return $description;
        }
        if ($plate !== null) {
            return $plate;
        }
        if ($vin !== null) {
            return $vin;
        }

        return 'Unidentified vehicle';
    }

    private static function present(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
