<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InventoryBalance;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryVendor;
use App\Models\Organization;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryLocationKind;
use App\Support\Inventory\InventoryLocationStatus;
use App\Support\Inventory\InventoryUnit;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Demo catalogue on one company so Inventory has something to open.
 *
 * Idempotent on SKU prefix DEMO-. `--live` is required against the
 * production database name; the company must be named on the command.
 */
class InventorySeedDemo extends Command
{
    protected $signature = 'inventory:seed-demo
                            {company : Company name, e.g. EzBizi}
                            {--live : Allow the production database}
                            {--force : Skip the confirmation}';

    protected $description = 'Plant a demo warehouse, vendor, items and stock on one company';

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $live = (bool) $this->option('live');

        if ($database === 'ezbizi' && ! $live) {
            $this->error("Refusing to seed production without --live.");

            return self::FAILURE;
        }

        $name = (string) $this->argument('company');

        $found = $this->findCompany($tenant, $name);

        if ($found === null) {
            $this->error("No company named {$name}.");

            return self::FAILURE;
        }

        [$organization, $company] = $found;

        if (! $this->option('force') && $live) {
            if (! $this->confirm("Seed demo inventory on {$organization->name} / {$company->name}?")) {
                return self::SUCCESS;
            }
        }

        $tenant->run($organization, function () use ($company, $numbers) {
            $franchise = Franchise::query()
                ->where('company_id', $company->id)
                ->orderBy('name')
                ->first();

            if ($franchise === null) {
                $franchise = Franchise::create([
                    'company_id' => $company->id,
                    'name' => 'Headquarters',
                    'timezone' => 'America/New_York',
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
            }

            $warehouse = $this->location($company, $franchise, 'Demo warehouse', InventoryLocationKind::Warehouse);
            $shop = $this->location($company, $franchise, 'Demo shop', InventoryLocationKind::Shop);

            $vendor = InventoryVendor::query()
                ->where('company_id', $company->id)
                ->where('name', 'Demo Supply Co')
                ->first()
                ?? InventoryVendor::create([
                    'company_id' => $company->id,
                    'franchise_id' => $franchise->id,
                    'name' => 'Demo Supply Co',
                    'phone' => '305-555-0100',
                    'email' => 'supply@example.test',
                ]);

            $liner = $this->item($company, $franchise, $numbers, 'DEMO-LINER', '6 mil liner', '10', 1850);
            $cap = $this->item($company, $franchise, $numbers, 'DEMO-CAP', 'Chimney cap', '4', 4200);
            $brush = $this->item($company, $franchise, $numbers, 'DEMO-BRUSH', 'Chimney brush 6 in', '6', 950);
            $box = $this->item($company, $franchise, $numbers, 'DEMO-BOX', 'Sample box', null, 250);

            $this->stock($liner, $warehouse, '40');
            $this->stock($liner, $shop, '8');
            $this->stock($cap, $warehouse, '12');
            $this->stock($brush, $warehouse, '25');
            $this->stock($box, $warehouse, '6');

            Customer::query()
                ->where('owning_franchise_id', $franchise->id)
                ->where('name', 'Demo Counter')
                ->first()
                ?? Customer::create([
                    'owning_franchise_id' => $franchise->id,
                    'number' => $numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => 'Demo Counter',
                    'email' => 'demo-counter@example.test',
                    'phone' => '305-555-0199',
                    'is_active' => true,
                ]);

            $this->info("Vendor {$vendor->name}; locations {$warehouse->name}, {$shop->name}.");
        });

        $this->info("Seeded demo inventory on {$company->name}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: Organization, 1: Company}|null
     */
    private function findCompany(TenantContext $tenant, string $name): ?array
    {
        foreach (Organization::query()->orderBy('name')->get() as $organization) {
            $company = $tenant->run(
                $organization,
                fn () => Company::query()->where('name', $name)->first(),
            );

            if ($company instanceof Company) {
                return [$organization, $company];
            }
        }

        return null;
    }

    private function location(
        Company $company,
        Franchise $franchise,
        string $name,
        InventoryLocationKind $kind,
    ): InventoryLocation {
        return InventoryLocation::query()
            ->where('company_id', $company->id)
            ->where('name', $name)
            ->first()
            ?? InventoryLocation::create([
                'company_id' => $company->id,
                'franchise_id' => $franchise->id,
                'name' => $name,
                'kind' => $kind,
                'status' => InventoryLocationStatus::Active,
            ]);
    }

    private function item(
        Company $company,
        Franchise $franchise,
        RecordNumberGenerator $numbers,
        string $sku,
        string $name,
        ?string $reorder,
        ?int $lastCostMinor = null,
    ): InventoryItem {
        $existing = InventoryItem::query()
            ->where('company_id', $company->id)
            ->where('sku', $sku)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return InventoryItem::create([
            'company_id' => $company->id,
            'franchise_id' => $franchise->id,
            'number' => $numbers->next(RecordNumberGenerator::ITEMS, $franchise),
            'sku' => $sku,
            'name' => $name,
            'unit' => InventoryUnit::Each,
            'status' => InventoryItemStatus::Active,
            'reorder_at' => $reorder,
            'last_cost_minor' => $lastCostMinor,
        ]);
    }

    private function stock(InventoryItem $item, InventoryLocation $location, string $qty): void
    {
        $row = InventoryBalance::query()
            ->where('item_id', $item->id)
            ->where('location_id', $location->id)
            ->first();

        if ($row === null) {
            InventoryBalance::create([
                'item_id' => $item->id,
                'location_id' => $location->id,
                'qty_on_hand' => $qty,
            ]);

            return;
        }

        if ((float) $row->qty_on_hand <= 0) {
            $row->qty_on_hand = $qty;
            $row->save();
        }
    }
}
