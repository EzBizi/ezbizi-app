<?php

namespace App\Support\Inventory;

/**
 * Cost attributes on inventory payloads (spec §7, §14).
 *
 * Without `inventory.financials`, every read path omits last cost and unit
 * cost. Controllers call [withoutCosts] on presented arrays rather than
 * branching per field.
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

        if (isset($row['lines']) && is_array($row['lines'])) {
            foreach ($row['lines'] as $i => $line) {
                if (is_array($line)) {
                    unset($row['lines'][$i]['unit_cost_minor']);
                }
            }
        }

        return $row;
    }
}
