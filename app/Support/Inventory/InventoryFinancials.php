<?php

namespace App\Support\Inventory;

/**
 * Cost attributes on inventory payloads (spec §7, §14).
 *
 * Without `inventory.financials`, every read path omits last cost and unit
 * cost, including nested PO lines and item last cost. Controllers call
 * [withoutCosts] on presented arrays rather than branching per field.
 */
final class InventoryFinancials
{
    /**
     * Drop cost keys from a presented row, including line unit costs.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withoutCosts(array $row): array
    {
        unset($row['last_cost_minor'], $row['unit_cost_minor']);

        foreach ($row as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $row[$key] = array_is_list($value)
                ? array_map(
                    static fn ($item) => is_array($item) ? self::withoutCosts($item) : $item,
                    $value,
                )
                : self::withoutCosts($value);
        }

        return $row;
    }
}
