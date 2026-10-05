<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\FieldSet;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The tools and equipment checklists become the technician's to fill, as in
 * A1 Tools (Jose, 2026-10-05: "that's something to be filled on the
 * technician side"). A new version of each live contract whose checklist the
 * employer filled; wording and every other question are kept. Onboardings
 * under way keep their pinned version.
 */
class ChecklistsToTechnician extends Command
{
    protected $signature = 'hr:checklists-to-technician
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Make contract checklists the employee\'s to fill';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($audit) {
            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            $plans = [];

            foreach (Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get() as $contract) {
                $current = $contract->currentVersion;
                $fields = $current?->fieldSet()->toArray() ?? [];
                $moved = false;
                foreach ($fields as &$field) {
                    if (($field['type'] ?? '') === FieldType::CHECKLIST && ($field['filled_by'] ?? '') !== FilledBy::EMPLOYEE) {
                        $field['filled_by'] = FilledBy::EMPLOYEE;
                        $moved = true;
                    }
                }
                unset($field);
                if (! $moved) {
                    continue;
                }

                try {
                    $set = FieldSet::fromInput($fields, $contract->pinnedKeyProperties());
                } catch (ValidationException $e) {
                    $this->error("{$contract->name}: ".collect($e->errors())->flatten()->implode(' ').' Nothing written.');

                    return self::FAILURE;
                }

                $this->line(sprintf('%s: checklists to the employee, as version %d',
                    $contract->name, (int) $contract->versions()->max('version') + 1));
                $plans[] = [$contract, $current, $set];
            }

            if ($this->option('dry-run')) {
                $this->info(count($plans).' contracts would change. Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($plans, $author, $audit) {
                foreach ($plans as [$contract, $current, $set]) {
                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version' => (int) $contract->versions()->max('version') + 1,
                        'fields' => $set->toArray(),
                        'body' => $current->body,
                        'notes' => 'Tools and equipment are the employee\'s to fill, as in A1 Tools.',
                        'created_by' => $author?->getKey(),
                        'cover_source' => $current->cover_source,
                        'cover_path' => $current->cover_path,
                        'cover_upload_id' => $current->cover_upload_id,
                        'cover_filename' => $current->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: ['change' => 'checklists_to_employee'], actor: $author);
                }
            });

            $this->info(count($plans).' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
