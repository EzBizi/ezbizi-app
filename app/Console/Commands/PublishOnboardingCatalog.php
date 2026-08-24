<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\FieldSet;
use App\Support\Hr\OnboardingCatalog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Publish the adapted A-1 role agreements as contract templates for one tenant.
 *
 * Catalog data, not a demo seed: these are real templates the Contracts tab
 * can pick. It is opt-in per organization so a new signup does not inherit
 * chimney-trade wording. Existing names are left alone unless `--replace`.
 */
class PublishOnboardingCatalog extends Command
{
    protected $signature = 'hr:publish-onboarding-catalog
                            {organization : The organization name to publish into}
                            {--dry-run : List the templates without writing}
                            {--replace : Publish a new version when the name already exists}';

    protected $description = 'Publish adapted A-1 onboarding contracts into an organization';

    public function handle(TenantContext $tenant, AuditLogger $audit): int
    {
        $name = (string) $this->argument('organization');
        $organization = Organization::query()->where('name', $name)->first();

        if ($organization === null) {
            $this->error("No organization named {$name}.");

            return self::FAILURE;
        }

        $templates = OnboardingCatalog::templates();

        if ($this->option('dry-run')) {
            foreach ($templates as $template) {
                $this->line(sprintf(
                    '%s — %d fields, %s characters',
                    $template['name'],
                    count($template['fields']),
                    number_format(mb_strlen($template['body'])),
                ));
            }
            $this->info(sprintf('%d templates. Nothing written.', count($templates)));

            return self::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $replaced = 0;

        $ok = $tenant->run($organization, function () use (
            $templates, $audit, &$created, &$skipped, &$replaced,
        ) {
            $author = User::query()->with('role')->get()
                ->first(fn (User $user) => $user->isAdministrator());

            if ($author === null) {
                $this->error('That organization has no administrator to record as the author.');

                return false;
            }

            foreach ($templates as $template) {
                $existing = Contract::query()->where('name', $template['name'])->first();

                if ($existing !== null && ! $this->option('replace')) {
                    $skipped++;
                    continue;
                }

                DB::transaction(function () use ($template, $existing, $author, $audit, &$created, &$replaced) {
                    if ($existing === null) {
                        $contract = Contract::create([
                            'name'        => $template['name'],
                            'description' => $template['description'],
                            'created_by'  => $author->getKey(),
                        ]);
                        $created++;
                        $action = 'contract.created';
                    } else {
                        $contract = $existing;
                        $contract->forceFill(['description' => $template['description']])->save();
                        $replaced++;
                        $action = 'contract.version_published';
                    }

                    // Held to the same history ratchet a human publish is: a
                    // replace cannot drop a key, change a type, or un-mark
                    // sensitive. The catalog already validated the pair; this
                    // is the second check against THIS contract's past.
                    //
                    // Keys the catalog no longer asks, and that are not now
                    // common core, stay on the list as retired — same as a
                    // person ticking Retired in the designer. Core keys are
                    // dropped: FieldSet allows that, and keeping them would
                    // be refused as a second home.
                    $fields = FieldSet::fromInput(
                        $this->withRetiredLeftovers($template['fields'], $existing),
                        $contract->pinnedKeyProperties(),
                    );

                    $next = (int) ($contract->versions()->max('version') ?? 0) + 1;

                    $version = ContractVersion::create([
                        'contract_id' => $contract->getKey(),
                        'version'     => $next,
                        'fields'      => $fields->toArray(),
                        'body'        => $template['body'],
                        'notes'       => $next === 1
                            ? 'Imported from the A-1 onboarding catalog.'
                            : 'Replaced from the A-1 onboarding catalog.',
                        'created_by'  => $author->getKey(),
                    ]);

                    $contract->forceFill(['current_version_id' => $version->getKey()])->save();

                    $audit->log($action, $contract, metadata: [
                        'fields'   => count($template['fields']),
                        'has_body' => true,
                        'catalog'  => $template['slug'],
                    ], actor: $author);
                });
            }

            return true;
        });

        if ($ok !== true) {
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Created %d, replaced %d, skipped %d (already present).',
            $created,
            $replaced,
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function withRetiredLeftovers(array $fields, ?Contract $existing): array
    {
        if ($existing === null) {
            return $fields;
        }

        $keep = [];
        foreach ($fields as $field) {
            if (is_string($field['key'] ?? null) && $field['key'] !== '') {
                $keep[$field['key']] = true;
            }
        }

        foreach ($existing->currentVersion?->fieldSet()->toArray() ?? [] as $previous) {
            $key = $previous['key'] ?? '';
            if ($key === '' || isset($keep[$key]) || CommonCore::claims($key)) {
                continue;
            }
            $previous['retired'] = true;
            $fields[] = $previous;
        }

        return $fields;
    }
}
