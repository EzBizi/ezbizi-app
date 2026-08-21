<?php

namespace App\Support\Hr;

use App\Support\Contracts\CommonCore;
use App\Support\Contracts\ContractFacts;
use App\Support\Contracts\FieldSet;
use App\Support\Contracts\MergeTags;
use App\Support\Hr\OnboardingCatalog\FieldLibrary;
use App\Support\Hr\OnboardingCatalog\SourceCompiler;
use RuntimeException;

/**
 * A-1 Tools onboarding agreements, rewritten as EzBizi contract templates.
 *
 * ── Structure first ──────────────────────────────────────────────
 *
 * These files came from A-1 Tools with 26 placeholders, three of which were
 * not fields at all (`hourly_only`, `raise_only`, `raise_unit` — clause
 * deletion at render time). EzBizi does not grow conditionals, signing-date
 * parts, or a reserved `{{name}}` to meet them. Each A-1 file becomes one or
 * more templates whose bodies only name keys this platform already merges.
 *
 * Mapping, stated once:
 *
 *   {{name}}             → {{first_name}} {{last_name}}
 *                          (`name` is reserved and derived; `middle_name` is
 *                          optional and would print "[Middle name not provided]"
 *                          for most people, so it stays off the party line)
 *   {{company_name}}     → {{organization.name}}
 *   {{company_address}}  → {{employer_address}}  (a field — there is no
 *                          organization.address fact)
 *   {{address}}          → {{address}}           (a field — not on the profile)
 *   {{date}} as hire date→ {{date_of_hire}}      (a field)
 *   {{day}} {{month}} 20{{year2}}
 *                        → {{document.date}}     (paperwork date, never signing)
 *   {{hourly_only}} / {{raise_only}} / {{raise_unit}}
 *                        → split templates, not tags
 *
 * Gaps we did not close, because closing them would be changing EzBizi to
 * match A-1:
 *
 *   no clause conditionals in the body
 *   no organization address as a ContractFact
 *   no home address on the common core
 *   no signing-date merge (DocumentRenderer is deterministic)
 *   no markdown, tables, or fillable checklists — lists are plain text
 *   signature lines come from signature fields, not from body placeholders
 */
final class OnboardingCatalog
{
    /**
     * @var array<string, array{name: string, description: string, signer: string, pay: string, raise: bool}>
     */
    private const ROLES = [
        'field_technician' => [
            'name'        => 'Field Technician',
            'description' => 'W-2 field technician: inspections, sweeps, repairs, and on-site sales.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'field_repair' => [
            'name'        => 'Field Repair Team',
            'description' => 'W-2 repair, installation, and construction crew.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'field_inspector' => [
            'name'        => 'Field Inspector (1099)',
            'description' => 'Independent contractor field inspector. Commission on completed jobs.',
            'signer'      => 'contractor',
            'pay'         => 'none',
            'raise'       => false,
        ],
        'project_manager' => [
            'name'        => 'Project Manager',
            'description' => 'W-2 office project manager: inventory, repair scheduling, and operations.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'local_field_manager' => [
            'name'        => 'Local Field Manager',
            'description' => 'W-2 local field manager: routes, CRM, and daily field support.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'office_employee' => [
            'name'        => 'Call Center Employee',
            'description' => 'W-2 office / call-center employee: booking, dispatch support, and customer contact.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'marketing' => [
            'name'        => 'Marketing',
            'description' => 'W-2 marketing: content, ads, and brand presence.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'customer_relations' => [
            'name'        => 'Customer Relations',
            'description' => 'W-2 customer relations: complaints, reputation, and follow-up.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'dispatch_supervisor' => [
            'name'        => 'Dispatch Supervisor',
            'description' => 'W-2 dispatch supervisor: call-center leadership and daily dispatch.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'accounting' => [
            'name'        => 'Accounting',
            'description' => 'W-2 accounting: bookkeeping, payroll, commissions, and reporting.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
        'sales_executive' => [
            'name'        => 'Sales Executive',
            'description' => 'W-2 sales executive: estimate follow-up and phone sales.',
            'signer'      => 'employee',
            'pay'         => 'split',
            'raise'       => true,
        ],
    ];

    /**
     * Every publishable template, already validated against FieldSet and MergeTags.
     *
     * @return list<array{slug: string, name: string, description: string, fields: array<int, array<string, mixed>>, body: string}>
     */
    public static function templates(): array
    {
        $out = [];

        foreach (self::ROLES as $slug => $role) {
            foreach (self::variantsFor($role) as $variant) {
                $out[] = self::build($slug, $role, $variant);
            }
        }

        return $out;
    }

    /**
     * @param  array{name: string, description: string, signer: string, pay: string, raise: bool}  $role
     * @return list<array{pay: string|null, raise: bool, keep: string[], name_suffix: string, description_suffix: string}>
     */
    private static function variantsFor(array $role): array
    {
        if ($role['pay'] === 'none') {
            return [[
                'pay'                => null,
                'raise'              => false,
                'keep'               => [],
                'name_suffix'        => '',
                'description_suffix' => '',
            ]];
        }

        $out = [];

        foreach ([SourceCompiler::HOURLY, SourceCompiler::SALARY] as $pay) {
            $raiseChoices = $role['raise'] ? [false, true] : [false];

            foreach ($raiseChoices as $raise) {
                $keep = [$pay];
                if ($raise) {
                    $keep[] = SourceCompiler::RAISE;
                }

                $payLabel = $pay === SourceCompiler::HOURLY ? 'hourly' : 'salary';
                $name     = $raise
                    ? " ({$payLabel}, scheduled increases)"
                    : " ({$payLabel})";
                $desc     = $raise
                    ? " Paid {$payLabel}, with a scheduled increase in the first twelve months."
                    : " Paid {$payLabel}, with no scheduled increase clause.";

                $out[] = [
                    'pay'                => $pay,
                    'raise'              => $raise,
                    'keep'               => $keep,
                    'name_suffix'        => $name,
                    'description_suffix' => $desc,
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array{name: string, description: string, signer: string, pay: string, raise: bool}  $role
     * @param  array{pay: string|null, raise: bool, keep: string[], name_suffix: string, description_suffix: string}  $variant
     * @return array{slug: string, name: string, description: string, fields: array<int, array<string, mixed>>, body: string}
     */
    private static function build(string $slug, array $role, array $variant): array
    {
        $path = resource_path("hr/onboarding-catalog/{$slug}.src.txt");

        if (! is_file($path)) {
            throw new RuntimeException("Onboarding catalog source missing: {$slug}.");
        }

        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Onboarding catalog source unreadable: {$slug}.");
        }

        $body   = SourceCompiler::compile($source, $variant['keep']);
        $body   = MergeTags::normalise($body);
        $fields = self::fieldsFor($body, $role['signer']);

        if ($body === null) {
            throw new RuntimeException("Onboarding catalog compiled an empty body for {$slug}.");
        }

        $set = FieldSet::fromInput($fields);
        MergeTags::validate($body, $set);

        return [
            'slug'        => $slug,
            'name'        => $role['name'].$variant['name_suffix'],
            'description' => $role['description'].$variant['description_suffix'],
            'fields'      => $set->toArray(),
            'body'        => $body,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fieldsFor(?string $body, string $signer): array
    {
        $named = [];

        if ($body !== null && preg_match_all('/\{\{([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]+)?)\}\}/', $body, $matches) > 0) {
            foreach ($matches[1] as $key) {
                if (CommonCore::claims($key) || ContractFacts::claims($key)) {
                    continue;
                }
                $named[$key] = true;
            }
        }

        $fields = [];

        foreach (array_keys(FieldLibrary::all($signer)) as $key) {
            if ($key === 'employer_signature' || $key === 'employee_signature') {
                continue;
            }
            if (! isset($named[$key])) {
                continue;
            }
            $fields[] = FieldLibrary::definition($key, $signer);
            unset($named[$key]);
        }

        if ($named !== []) {
            throw new RuntimeException(sprintf(
                'Onboarding catalog body names fields with no library entry: %s.',
                implode(', ', array_keys($named)),
            ));
        }

        $fields[] = FieldLibrary::definition('employer_signature', $signer);
        $fields[] = FieldLibrary::definition('employee_signature', $signer);

        return $fields;
    }
}
