<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give every live contract without a cover the cover one contract already
 * has, as a new version of each (Jose, 2026-10-05: "the final pdf is also
 * missing the cover"; A1-Group's one cover is on Call Center Employee).
 * Wording and questions are kept; onboardings under way keep their version.
 */
class CopyContractCover extends Command
{
    protected $signature = 'hr:copy-contract-cover
                            {organization : The organization name}
                            {from : The contract whose current cover is copied}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Copy one contract\'s cover to every contract that has none';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $organization = Organization::query()->where('name', $this->argument('organization'))->first();
        if ($organization === null) {
            $this->error('No organization by that name.');

            return self::FAILURE;
        }

        return $tenant->run($organization, function () use ($audit) {
            $source = Contract::query()->where('name', $this->argument('from'))->with('currentVersion')->first()?->currentVersion;
            if ($source === null || ! $source->hasCover()) {
                $this->error('That contract has no cover to copy.');

                return self::FAILURE;
            }
            $author = User::query()->with('role')->get()->first(fn (User $u) => $u->isAdministrator());

            $targets = Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get()
                ->filter(fn (Contract $c) => $c->currentVersion !== null && ! $c->currentVersion->hasCover());

            foreach ($targets as $contract) {
                $this->line(sprintf('%s: cover as version %d', $contract->name, (int) $contract->versions()->max('version') + 1));
            }

            if ($this->option('dry-run')) {
                $this->info($targets->count().' contracts would change. Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($targets, $source, $author, $audit) {
                foreach ($targets as $contract) {
                    $current = $contract->currentVersion;
                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version' => (int) $contract->versions()->max('version') + 1,
                        'fields' => $current->fieldSet()->toArray(),
                        'body' => $current->body,
                        'notes' => 'Cover added, the same as the organization\'s other contracts.',
                        'created_by' => $author?->getKey(),
                        'cover_source' => $source->cover_source,
                        'cover_path' => $source->cover_path,
                        'cover_upload_id' => $source->cover_upload_id,
                        'cover_filename' => $source->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: ['change' => 'cover_copied'], actor: $author);
                }
            });

            $this->info($targets->count().' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
