<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\InspectionCode;
use App\Models\InspectionDefinition;
use App\Models\Organization;
use App\Support\Inspection\FieldSet;
use App\Support\Inspection\Port\A1Inspection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Author A1's inspection into an organization, as ordinary tenant data.
 *
 * ── This is the proof, not a convenience ─────────────────────────
 *
 * The whole engine — conditions, findings nested inside options, pass/fail
 * variants, cited codes, priced work, print modes — was built so that A1's
 * inspection could be DATA. This command is where that claim is tested: it
 * writes the definition through `FieldSet`, the same validator the Builder
 * uses, so anything the engine cannot express fails here rather than being
 * quietly special-cased.
 *
 * What it produces is not privileged in any way. No code path reads
 * `Port\A1Inspection` at runtime; A1 edits the result in the Builder like any
 * other inspection, which is the entire objective and the thing the original —
 * 2,818 lines of TypeScript — could never do.
 *
 * ── It does not publish ──────────────────────────────────────────
 *
 * A draft, deliberately. Publishing writes a written-once version and points
 * the picker at it, and that is A1's decision to make after they have read what
 * arrived — not a side effect of an import somebody ran. Publishing also
 * freezes the code citations, so it should happen after they have checked the
 * wording is the wording they stand behind.
 */
class InspectionImportA1 extends Command
{
    protected $signature = 'inspection:import-a1
                            {organization : the organization to import into}
                            {--company= : the company it belongs to, by name}
                            {--name=Level 1 / Level 2 Chimney Inspection : what to call it}';

    protected $description = "Author A1 Chimney's inspection as an editable draft";

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()
            ->where('name', $this->argument('organization'))
            ->first();

        if ($organization === null) {
            $this->error(sprintf("No organization called '%s'.", $this->argument('organization')));

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($organization) {
            $company = null;

            if ($this->option('company') !== null) {
                $company = Company::query()->where('name', $this->option('company'))->first();

                if ($company === null) {
                    $this->error(sprintf(
                        "No company called '%s' in %s.",
                        $this->option('company'),
                        $organization->name,
                    ));

                    return self::FAILURE;
                }
            }

            $name = (string) $this->option('name');

            if (InspectionDefinition::query()->where('name', $name)->exists()) {
                // Refused rather than updated. An import that overwrote would
                // discard whatever A1 had edited since the last one, which is
                // the opposite of the point.
                $this->error(sprintf(
                    "'%s' already exists here. Rename it or pass --name= to import alongside it.",
                    $name,
                ));

                return self::FAILURE;
            }

            $definition = DB::transaction(function () use ($name, $company) {
                $codeIds = $this->codes();

                // Through FieldSet, the Builder's own validator. If the engine
                // cannot express something, this throws here rather than
                // producing a definition the Builder would refuse to save.
                $screens = FieldSet::fromInput(A1Inspection::screens($codeIds))->toArray();

                return InspectionDefinition::create([
                    'name' => $name,
                    'created_by' => null,
                    'screens' => $screens,
                    'scope_type' => $company === null ? null : InspectionDefinition::SCOPE_COMPANY,
                    'scope_id' => $company?->getKey(),
                ]);
            });

            $this->info(sprintf('Imported "%s" as a draft.', $definition->name));
            $this->line(sprintf('  screens: %d', count($definition->screens)));
            $this->line(sprintf('  fields:  %d', $this->countFields($definition->screens)));
            $this->line(sprintf(
                '  belongs to: %s',
                $company === null ? 'the whole organization' : $company->name,
            ));
            $this->newLine();
            $this->line('Not published. Read it in the Builder, then publish when it is right.');

            return self::SUCCESS;
        });
    }

    /**
     * The building codes, written once and reused.
     *
     * Matched on the code number so re-importing into an organization that
     * already has them cites the existing rows rather than creating a second
     * `R1003.11` nobody asked for.
     *
     * @return array<string, string>  reference => id
     */
    private function codes(): array
    {
        $ids = [];

        foreach (A1Inspection::CODES as $reference => $code) {
            $existing = InspectionCode::query()->where('code', $code['code'])->first();

            $ids[$reference] = $existing?->getKey()
                ?? InspectionCode::create($code)->getKey();
        }

        return $ids;
    }

    /** @param list<array<string, mixed>> $screens */
    private function countFields(array $screens): int
    {
        $count = 0;

        foreach ($screens as $screen) {
            foreach (($screen['fields'] ?? []) as $field) {
                $count++;

                foreach (($field['options'] ?? []) as $option) {
                    $count += count($option['fields'] ?? []);
                }
            }
        }

        return $count;
    }
}
