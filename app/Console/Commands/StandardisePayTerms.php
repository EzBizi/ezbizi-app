<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\FieldSet;
use App\Support\Hr\StandardPayTerms;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The same pay terms on every live contract of one organization, each its own
 * line to fill, mark "To be determined" or turn off (Eve via Jose,
 * 2026-10-06). A new version of each contract that has a COMPENSATION
 * section; the rest of its wording is kept (StandardPayTerms). Plans and
 * validates every contract before writing any. Onboardings under way keep
 * their pinned version.
 */
class StandardisePayTerms extends Command
{
    protected $signature = 'hr:standard-pay-terms
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Give every contract the same pay terms, each one on its own';

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
            $refused = [];

            foreach (Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get() as $contract) {
                $current = $contract->currentVersion;
                if ($current === null) {
                    continue;
                }
                $body = StandardPayTerms::body((string) $current->body);
                if ($body === null) {
                    $this->line("{$contract->name}: no COMPENSATION section, left as it is");

                    continue;
                }
                try {
                    $set = FieldSet::fromInput(
                        StandardPayTerms::fields($current->fieldSet()->toArray()),
                        $contract->pinnedKeyProperties(),
                    );
                } catch (ValidationException $e) {
                    $refused[] = "{$contract->name}: ".collect($e->errors())->flatten()->implode(' ');

                    continue;
                }
                if ($body === str_replace("\r\n", "\n", (string) $current->body)
                    && $set->toArray() === $current->fieldSet()->toArray()) {
                    $this->line("{$contract->name}: already standard");

                    continue;
                }

                $this->line(sprintf('%s: standard pay terms as version %d', $contract->name,
                    (int) $contract->versions()->max('version') + 1));
                $plans[] = [$contract, $current, $set, $body];
            }

            if ($refused !== []) {
                foreach ($refused as $line) {
                    $this->error($line);
                }
                $this->error(count($refused).' contracts would be refused. Nothing written.');

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info(count($plans).' contracts would change. Dry run. Nothing written.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($plans, $author, $audit) {
                foreach ($plans as [$contract, $current, $set, $body]) {
                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version' => (int) $contract->versions()->max('version') + 1,
                        'fields' => $set->toArray(),
                        'body' => $body,
                        'notes' => 'The same pay terms as every contract, each one on its own.',
                        'created_by' => $author?->getKey(),
                        'cover_source' => $current->cover_source,
                        'cover_path' => $current->cover_path,
                        'cover_upload_id' => $current->cover_upload_id,
                        'cover_filename' => $current->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: ['change' => 'standard_pay_terms'], actor: $author);
                }
            });

            $this->info(count($plans).' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
