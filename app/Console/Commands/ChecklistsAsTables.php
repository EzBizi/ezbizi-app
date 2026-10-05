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
use Illuminate\Validation\ValidationException;

/**
 * The tools and equipment lists as tables, the way A1 Tools printed them
 * (Jose, 2026-10-05: "this should be a table"). The Field Technician and
 * Field Repair Team agreements spelled the lists out as bullets; this gives
 * each live contract that does a new version where each list is its
 * checklist question (item, quantity, received), which the document draws as
 * a table. Wording and every other question are kept. Plans and validates
 * every contract before writing any, like hr:add-new-hire-questions.
 */
class ChecklistsAsTables extends Command
{
    protected $signature = 'hr:checklists-as-tables
                            {organization : The organization name}
                            {--dry-run : Say what would change without writing}';

    protected $description = 'Turn bullet-listed tools and equipment in contracts into checklist tables';

    private const LISTS = ['Tools Checklist' => 'tools', 'Equipment Checklist' => 'equipment'];

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

            $plans = [];
            $refused = [];
            foreach (Contract::query()->whereNull('archived_at')->with('currentVersion')->orderBy('name')->get() as $contract) {
                $current = $contract->currentVersion;
                if ($current === null) {
                    continue;
                }

                $body = (string) $current->body;
                $fields = $current->fieldSet()->toArray();
                $have = array_column($fields, 'key');
                $adding = [];
                foreach (self::LISTS as $heading => $key) {
                    $body = preg_replace_callback(
                        '/(?m)^'.preg_quote($heading, '/').'\R\R(?:• .*(?:\R|$))+/u',
                        function () use ($heading, $key, $have, &$adding) {
                            if (! in_array($key, $have, true)) {
                                $adding[] = FieldLibrary::definition($key);
                            }

                            return "## {$heading}\n\n{{{$key}}}\n";
                        },
                        $body,
                    ) ?? $body;
                }

                if ($body === (string) $current->body) {
                    continue;
                }

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

                $this->line(sprintf('%s: lists as tables, as version %d', $contract->name,
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
                        'notes' => 'Tools and equipment as checklist tables.',
                        'created_by' => $author->getKey(),
                        'cover_source' => $current->cover_source,
                        'cover_path' => $current->cover_path,
                        'cover_upload_id' => $current->cover_upload_id,
                        'cover_filename' => $current->cover_filename,
                    ]);
                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();
                    $audit->log('contract.version_published', $contract, metadata: ['change' => 'checklists_as_tables']);
                }
            });

            $this->info(count($plans).' contracts given a new version.');

            return self::SUCCESS;
        });
    }
}
