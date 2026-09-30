<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\FieldSet;
use App\Support\Hr\OnboardingCatalog\FieldLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Add the new-hire questions (tax, bank, documents) to every live contract
 * of one organization, as a new version of each (Jose, 2026-09-30).
 *
 * Not `hr:publish-onboarding-catalog --replace`: that rewrites the wording
 * from the catalog, and an organization's contracts have versions of their
 * own and variants the catalog never had. This keeps each contract's current
 * wording and questions and only adds the ones it lacks. Onboardings already
 * running stay on the version they started with, as every publish does.
 */
class AddNewHireQuestions extends Command
{
    protected $signature = 'hr:add-new-hire-questions
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Add the tax, bank and document questions to an organization\'s contracts';

    private const FIELD_ROLE_NAMES = ['Field Technician', 'Field Repair Team', 'Field Inspector'];

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($audit) {
            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());
            if ($author === null) {
                $this->error('That organization has no administrator to record as the author.');

                return self::FAILURE;
            }

            $changed = 0;
            foreach (Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get() as $contract) {
                $current = $contract->currentVersion;
                if ($current === null) {
                    continue;
                }

                $fields = $current->fieldSet()->toArray();
                $have = array_column($fields, 'key');
                $fieldRole = collect(self::FIELD_ROLE_NAMES)->contains(fn ($n) => str_starts_with($contract->name, $n));
                $adding = array_values(array_filter(
                    FieldLibrary::newHire($fieldRole),
                    fn (array $f) => ! in_array($f['key'], $have, true),
                ));

                $this->line(sprintf('%s: %s', $contract->name, $adding === []
                    ? 'already has them'
                    : 'adds '.count($adding).' as version '.($current->version + 1)));

                if ($adding === [] || $this->option('dry-run')) {
                    continue;
                }

                // Before the signature lines, which stay last on the form.
                $signatures = array_filter($fields, fn ($f) => ($f['type'] ?? '') === 'signature');
                $others = array_filter($fields, fn ($f) => ($f['type'] ?? '') !== 'signature');
                $set = FieldSet::fromInput(
                    [...array_values($others), ...$adding, ...array_values($signatures)],
                    $contract->pinnedKeyProperties(),
                );

                DB::transaction(function () use ($contract, $current, $set, $author, $audit, $adding) {
                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version' => (int) $contract->versions()->max('version') + 1,
                        'fields' => $set->toArray(),
                        'body' => $current->body,
                        'notes' => 'Added the new-hire questions: tax, bank and documents.',
                        'created_by' => $author->getKey(),
                        'cover_source' => $current->cover_source,
                        'cover_path' => $current->cover_path,
                        'cover_upload_id' => $current->cover_upload_id,
                        'cover_filename' => $current->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: [
                        'fields_added' => array_column($adding, 'key'),
                    ], actor: $author);
                });
                $changed++;
            }

            $this->info($this->option('dry-run') ? 'Dry run. Nothing written.' : "{$changed} contracts given a new version.");

            return self::SUCCESS;
        });
    }
}
