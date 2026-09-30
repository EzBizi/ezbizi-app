<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\FieldSet;
use App\Support\Hr\OnboardingCatalog\FieldLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

            // Every contract is planned and validated first, and nothing is
            // written unless all of them pass: a dry run predicts exactly what
            // the write would refuse, and a refusal never leaves the run half done.
            $plans = [];
            $refused = [];
            foreach (Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get() as $contract) {
                $current = $contract->currentVersion;
                if ($current === null) {
                    continue;
                }

                // Keys the profile now owns (the home address, since
                // 2026-09) are dropped, as the catalog's --replace drops them:
                // FieldSet refuses a contract that still declares one.
                $all = $current->fieldSet()->toArray();
                $fields = array_values(array_filter(
                    $all,
                    fn (array $f) => ! CommonCore::claims((string) ($f['key'] ?? '')),
                ));
                $dropping = array_values(array_diff(array_column($all, 'key'), array_column($fields, 'key')));
                $have = array_column($fields, 'key');
                $fieldRole = collect(self::FIELD_ROLE_NAMES)->contains(fn ($n) => str_starts_with($contract->name, $n));
                $adding = array_values(array_filter(
                    FieldLibrary::newHire($fieldRole),
                    fn (array $f) => ! in_array($f['key'], $have, true),
                ));

                if ($adding === [] && $dropping === []) {
                    $this->line("{$contract->name}: already has them");

                    continue;
                }

                // Before the signature lines, which stay last on the form.
                $signatures = array_filter($fields, fn ($f) => ($f['type'] ?? '') === 'signature');
                $others = array_filter($fields, fn ($f) => ($f['type'] ?? '') !== 'signature');

                try {
                    $set = FieldSet::fromInput(
                        [...array_values($others), ...$adding, ...array_values($signatures)],
                        $contract->pinnedKeyProperties(),
                    );
                } catch (ValidationException $e) {
                    $refused[] = "{$contract->name}: ".collect($e->errors())->flatten()->implode(' ');

                    continue;
                }

                $this->line(sprintf('%s: %s as version %d', $contract->name, implode(', ', array_filter([
                    $adding === [] ? null : 'adds '.count($adding),
                    $dropping === [] ? null : 'drops '.implode(', ', $dropping),
                ])), (int) $contract->versions()->max('version') + 1));
                $plans[] = [$contract, $current, $set, $adding, $dropping];
            }

            if ($refused !== []) {
                foreach ($refused as $line) {
                    $this->error($line);
                }
                $this->error(count($refused).' contracts would be refused. Nothing written.');

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info('Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($plans, $author, $audit) {
                foreach ($plans as [$contract, $current, $set, $adding, $dropping]) {
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
                        'fields_dropped' => $dropping,
                    ], actor: $author);
                }
            });

            $this->info(count($plans).' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
