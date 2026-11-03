<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryVendor;
use App\Models\User;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Inventory\InventoryMover;
use App\Support\Inventory\InventoryUnit;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * A1 Tools' parts into a company's Inventory (Jose, 2026-11-03: "data is
 * correct, import").
 *
 * Reads a JSON export of A1's `crm_inventory_*` rows: {items: [{sku, name,
 * size, unit, unit_cost_cents, is_active}], vendors: [name], stock: {branch:
 * {sku: qty}}}. A1 held stock per BRANCH, not per shelf, so each branch
 * becomes one warehouse named after it, on that branch.
 *
 * A1's shape is kept: an item is its name plus its size ("Galvanized Elbow
 * 4\""), because A1 told seven Lightweight-Flex kits apart only by size and
 * EzBizi has no size field. Opening stock goes through InventoryMover::adjust,
 * so every quantity has an adjustment row behind it like a hand count.
 *
 * Idempotent: an item is found by SKU in the company, a vendor by name, a
 * warehouse by name on its branch; stock is set only where that shelf is still
 * at zero, so a second run (or a count somebody has made since) is left alone.
 */
class InventoryImportA1 extends Command
{
    protected $signature = 'inventory:import-a1
                            {file : JSON export of A1 Tools crm_inventory rows}
                            {--company= : company id to import into}
                            {--as= : email of the user the opening counts are recorded by}
                            {--dry-run : report what would be written, write nothing}';

    protected $description = "Import A1 Tools' parts, vendor and opening stock into Inventory";

    public function handle(TenantContext $tenant, InventoryMover $mover, RecordNumberGenerator $numbers): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true, flags: JSON_THROW_ON_ERROR);
        $company = Company::query()->withoutGlobalScopes()->findOrFail($this->option('company'));
        $user = User::query()->withoutGlobalScopes()->where('email', $this->option('as'))->firstOrFail();
        $dry = (bool) $this->option('dry-run');
        $counts = ['items' => 0, 'items_existing' => 0, 'vendors' => 0, 'warehouses' => 0, 'stock_rows' => 0, 'stock_skipped' => 0, 'units' => 0];

        $tenant->run($company->organization, function () use ($data, $company, $user, $dry, $mover, $numbers, &$counts) {
            $items = [];
            foreach ($data['items'] as $row) {
                $existing = InventoryItem::query()->where('company_id', $company->id)
                    ->whereNull('franchise_id')->where('sku', $row['sku'])->first();
                if ($existing !== null) {
                    $counts['items_existing']++;
                    $items[$row['sku']] = $existing;

                    continue;
                }

                $unit = InventoryUnit::tryFrom((string) $row['unit']);
                if ($unit === null) {
                    // A1's form had no unit list: BOC1419 says "11".
                    $this->warn("{$row['sku']}: unit '{$row['unit']}' is not a unit; imported as each.");
                    $unit = InventoryUnit::Each;
                }

                $counts['items']++;
                $items[$row['sku']] = $dry ? null : InventoryItem::create([
                    'company_id' => $company->id,
                    'number' => $numbers->next(RecordNumberGenerator::ITEMS, $company),
                    'sku' => $row['sku'],
                    'name' => trim($row['name'].' '.($row['size'] ?? '')),
                    'unit' => $unit,
                    'status' => $row['is_active'] ? InventoryItemStatus::Active : InventoryItemStatus::Discontinued,
                    'last_cost_minor' => $row['unit_cost_cents'] ?: null,
                ]);
            }

            foreach ($data['vendors'] as $name) {
                if (! InventoryVendor::query()->where('company_id', $company->id)->where('name', $name)->exists()) {
                    $counts['vendors']++;
                    $dry || InventoryVendor::create(['company_id' => $company->id, 'name' => $name]);
                }
            }

            foreach ($data['stock'] as $branch => $levels) {
                $franchise = Franchise::query()->where('company_id', $company->id)->where('name', $branch)->firstOrFail();
                $place = InventoryLocation::query()->where('franchise_id', $franchise->id)->where('name', $branch)->first();
                if ($place === null) {
                    $counts['warehouses']++;
                    $place = $dry ? null : InventoryLocation::create([
                        'company_id' => $company->id,
                        'franchise_id' => $franchise->id,
                        'name' => $branch,
                        'kind' => InventoryLocationKind::Warehouse,
                        'status' => InventoryLocationStatus::Active,
                    ]);
                }

                foreach ($levels as $sku => $qty) {
                    $item = $items[$sku] ?? null;
                    $held = ($place && $item) ? InventoryBalance::query()
                        ->where('location_id', $place->id)->where('item_id', $item->id)->value('qty_on_hand') : null;
                    if ($held !== null && (float) $held != 0.0) {
                        $counts['stock_skipped']++;

                        continue;
                    }
                    $counts['stock_rows']++;
                    $counts['units'] += $qty;
                    $dry || $mover->adjust($place, $item, (string) $qty, 'Opening stock from A1 Tools', $user);
                }
            }
        });

        $this->info(($dry ? '[dry run] ' : '').json_encode($counts));

        return self::SUCCESS;
    }
}
