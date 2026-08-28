<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehicleOwnershipPeriod;
use App\Support\Fleet\VehicleName;
use App\Support\Fleet\VehicleOwnershipKind;
use App\Support\Fleet\VehicleStatus;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carry an A1 Tools `crm_vehicles` export onto the EzBizi fleet register.
 *
 * Takes a JSON file rather than reaching into the A1 Tools database, so the
 * read and the write stay separable: the export can be inspected, re-run, and
 * tested without a second live connection. A1 Tools is read-only to us.
 *
 * Idempotent on VIN within the organization — re-running skips what already
 * landed rather than minting a second row and a second V- number.
 */
class ImportA1ToolsVehicles extends Command
{
    protected $signature = 'fleet:import-a1-tools
        {file : JSON array exported from crm_vehicles}
        {--organization= : Organization name}
        {--company= : Company the vehicles belong to}
        {--dry-run : Report what would be written and write nothing}';

    protected $description = 'Import an A1 Tools vehicle export into the fleet register';

    public function handle(TenantContext $tenant, RecordNumberGenerator $numbers): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $rows = json_decode((string) file_get_contents($path), true);
        if (! is_array($rows)) {
            $this->error('That file is not a JSON array of vehicle rows.');

            return self::FAILURE;
        }

        $organization = Organization::query()
            ->where('name', $this->option('organization'))
            ->first();
        if ($organization === null) {
            $this->error('Organization not found: '.$this->option('organization'));

            return self::FAILURE;
        }

        $tenant->set($organization);

        $company = Company::query()
            ->where('organization_id', $organization->getKey())
            ->where('name', $this->option('company'))
            ->first();
        if ($company === null) {
            $this->error('Company not found: '.$this->option('company'));

            return self::FAILURE;
        }

        // Places are matched by name within this company only. A franchise
        // called Connecticut also exists under other companies; filing a van
        // on one of those would be reach, not the view.
        $places = Franchise::query()
            ->where('organization_id', $organization->getKey())
            ->where('company_id', $company->getKey())
            ->pluck('id', 'name');

        $dry = (bool) $this->option('dry-run');
        $made = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $vin = $this->text($row['vin'] ?? null);
            // Not $row['label'] — that is A1 Tools' own string, and it carries
            // the '· NA' the guard below strips. A dry run printing the source
            // label cannot distinguish a row this import fixes from one it does
            // not, which is how four '(NEW)' plates read as already-handled.
            $label = VehicleName::derive(
                $this->int($row['year'] ?? null),
                $this->text($row['make'] ?? null),
                $this->text($row['model'] ?? null),
                $this->text($row['license_plate'] ?? null),
                $vin,
            );

            if ($vin !== null && Vehicle::query()
                ->where('organization_id', $organization->getKey())
                ->where('vin', $vin)
                ->exists()
            ) {
                $this->line("skip  {$label} — VIN already on the register");
                $skipped++;

                continue;
            }

            $placeName = $this->text($row['franchise_name'] ?? null);
            $franchiseId = $placeName === null ? null : ($places[$placeName] ?? null);
            if ($placeName !== null && $franchiseId === null) {
                $this->warn("skip  {$label} — no place called {$placeName} under {$company->name}");
                $skipped++;

                continue;
            }

            // A1 Tools marks removal with is_active rather than a status, and
            // "active" on a row somebody deleted would put a van back in
            // service. The register keeps the row; the status tells the truth.
            $status = ((int) ($row['is_active'] ?? 1)) === 1
                ? VehicleStatus::from($this->text($row['status'] ?? null) ?? 'active')
                : VehicleStatus::OutOfService;

            if ($dry) {
                $this->line("would  {$label} → {$placeName} ({$status->value})");
                $made++;

                continue;
            }

            DB::transaction(function () use (
                $row, $company, $franchiseId, $status, $numbers, $organization, $label
            ) {
                $scope = $franchiseId === null
                    ? $company
                    : Franchise::query()->findOrFail($franchiseId);

                $vehicle = Vehicle::create([
                    'company_id' => $company->getKey(),
                    'franchise_id' => $franchiseId,
                    'number' => $numbers->next(RecordNumberGenerator::VEHICLES, $scope),
                    'year' => $this->int($row['year'] ?? null),
                    'make' => $this->text($row['make'] ?? null),
                    'model' => $this->text($row['model'] ?? null),
                    'trim' => $this->text($row['trim'] ?? null),
                    'plate' => $this->text($row['license_plate'] ?? null),
                    'plate_state' => $this->text($row['plate_state'] ?? null),
                    'vin' => $this->text($row['vin'] ?? null),
                    'colour' => $this->text($row['color'] ?? null),
                    'body_type' => $this->text($row['body_type'] ?? null) ?? 'other',
                    'fuel_type' => $this->text($row['fuel_type'] ?? null) ?? 'other',
                    'odometer_miles' => $this->int($row['odometer'] ?? null),
                    'odometer_updated_at' => $this->stamp($row['odometer_updated_at'] ?? null),
                    'next_service_on' => $this->day($row['next_oil_change_on'] ?? null),
                    'next_service_miles' => $this->int($row['next_oil_change_odometer'] ?? null),
                    'registration_expires_on' => $this->day($row['registration_expires_on'] ?? null),
                    'inspection_expires_on' => $this->day($row['inspection_expires_on'] ?? null),
                    'toll_device_number' => $this->text($row['toll_device_number'] ?? null),
                    'toll_plan' => $this->text($row['toll_plan'] ?? null),
                    'toll_flat_rate_cents' => $this->int($row['toll_flat_rate_cents'] ?? null),
                    'notes' => $this->text($row['notes'] ?? null),
                    'status' => $status,
                ]);

                $rented = $this->text($row['ownership'] ?? null) === 'rented';

                /*
                 * `started_on` is NOT NULL and A1 Tools filled a purchase date
                 * on one row in twenty-six. Falling back to when the row was
                 * created says "on the register by at least this date", which
                 * is true and checkable, rather than inventing a purchase.
                 */
                $started = $this->day($rented
                    ? ($row['rental_start_date'] ?? null)
                    : ($row['purchase_date'] ?? null))
                    ?? $this->day($row['created_at'] ?? null)
                    ?? now()->toDateString();

                VehicleOwnershipPeriod::create([
                    'vehicle_id' => $vehicle->getKey(),
                    'kind' => $rented ? VehicleOwnershipKind::Rented : VehicleOwnershipKind::Owned,
                    'counterparty' => $this->text($rented
                        ? ($row['rental_company'] ?? null)
                        : ($row['purchased_from'] ?? null)),
                    'registered_owner' => $this->text($row['registered_owner'] ?? null),
                    'started_on' => $started,
                    'purchase_price_cents' => $this->int($row['purchase_price_cents'] ?? null),
                    'lienholder' => $this->text($row['lienholder'] ?? null),
                    'loan_account' => $this->text($row['loan_account'] ?? null),
                    'loan_monthly_payment_cents' => $this->int($row['loan_monthly_payment_cents'] ?? null),
                    'loan_payoff_date' => $this->day($row['loan_payoff_date'] ?? null),
                    'transport_cost_cents' => $this->int($row['transport_cost_cents'] ?? null),
                    'rental_rate_cents' => $this->int($row['rental_rate_cents'] ?? null),
                    'rental_rate_period' => $this->text($row['rental_rate_period'] ?? null),
                    'rental_deposit_cents' => $this->int($row['rental_deposit_cents'] ?? null),
                    'rental_account_number' => $this->text($row['rental_account_number'] ?? null),
                    'rental_payment_terms' => $this->text($row['rental_payment_terms'] ?? null),
                    'rental_payment_method' => $this->text($row['rental_payment_method'] ?? null),
                    'rental_agreement_number' => $this->text($row['rental_agreement_number'] ?? null),
                    'mileage_allowance' => $this->int($row['mileage_allowance'] ?? null),
                ]);

                $carrier = $this->text($row['insurance_carrier'] ?? null);
                if ($carrier !== null) {
                    VehicleInsurancePolicy::create([
                        'vehicle_id' => $vehicle->getKey(),
                        'carrier' => $carrier,
                        'policy_number' => $this->text($row['insurance_policy_number'] ?? null),
                        'coverage' => $this->text($row['insurance_coverage'] ?? null),
                        'started_on' => $this->day($row['insurance_issued_on'] ?? null)
                            ?? $this->day($row['created_at'] ?? null)
                            ?? now()->toDateString(),
                        'expires_on' => $this->day($row['insurance_expires_on'] ?? null),
                        'premium_cents' => $this->int($row['insurance_premium_cents'] ?? null),
                        'premium_period' => $this->text($row['insurance_premium_period'] ?? null),
                        'agent' => $this->text($row['insurance_agent'] ?? null),
                        'agent_phone' => $this->text($row['insurance_agent_phone'] ?? null),
                        'listed_drivers' => $this->text($row['insurance_listed_drivers'] ?? null),
                    ]);
                }

                $this->line("made  {$vehicle->number}  {$vehicle->label}");
            });

            $made++;
        }

        $this->info(($dry ? 'Would import ' : 'Imported ')."{$made}, skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * A1 Tools has no empty state in its forms, so people typed the absence in:
     * thirty-four values across plate, lienholder, loan account and
     * purchased-from are a literal 'NA', 'N/A' or '(NEW)'. Carried across
     * verbatim they would read as a lienholder called NA on the ownership card,
     * and the derived label would end '· N/A'. They mean nothing, so they
     * arrive as nothing.
     *
     * '(NEW)' is here because the first dry run against the real export found
     * four of them still going through — a van awaiting plates is exactly as
     * plateless as one whose plate nobody typed.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        $sentinels = ['NA', 'N/A', 'N.A.', 'NONE', 'NEW', '(NEW)', 'TBD', 'UNKNOWN', '-', '--'];

        if ($text === '' || in_array(strtoupper($text), $sentinels, true)) {
            return null;
        }

        return $text;
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** A1 Tools writes '0000-00-00' where a date was never set. */
    private function day(mixed $value): ?string
    {
        $text = $this->text($value);
        if ($text === null || str_starts_with($text, '0000-')) {
            return null;
        }

        return substr($text, 0, 10);
    }

    private function stamp(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null || str_starts_with($text, '0000-') ? null : $text;
    }
}
