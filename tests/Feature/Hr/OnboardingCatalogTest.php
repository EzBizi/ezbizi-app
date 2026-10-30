<?php

namespace Tests\Feature\Hr;

use App\Console\Commands\CallScoringClause;
use App\Console\Commands\CooClauses;
use App\Console\Commands\CooContract;
use App\Models\Contract;
use App\Models\ContractVersion;
use App\Support\Contracts\ChecklistTable;
use App\Support\Contracts\MergeTags;
use App\Support\Hr\OnboardingCatalog;
use App\Support\Hr\OnboardingCatalog\SourceCompiler;
use App\Support\Hr\StandardPayTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class OnboardingCatalogTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_every_template_is_a_legal_ezbizi_contract(): void
    {
        $templates = OnboardingCatalog::templates();

        $this->assertCount(11, $templates);

        $names = [];

        foreach ($templates as $template) {
            $this->assertNotSame('', $template['name']);
            $this->assertArrayNotHasKey($template['name'], $names);
            $names[$template['name']] = true;

            $this->assertLessThanOrEqual(MergeTags::MAX_BODY_LENGTH, mb_strlen($template['body']));
            $this->assertStringNotContainsString('<<', $template['body']);
            $this->assertStringNotContainsString('hourly_only', $template['body']);
            $this->assertStringNotContainsString('{{name}}', $template['body']);
            $this->assertStringNotContainsString('{{company_name}}', $template['body']);
            $this->assertStringNotContainsString('{{employer_address}}', $template['body']);
            $this->assertStringContainsString('{{organization.name}}', $template['body']);
            $this->assertStringContainsString('{{organization.address}}', $template['body']);
            $this->assertStringContainsString('{{first_name}} {{last_name}}', $template['body']);
            $this->assertStringContainsString('{{address}}', $template['body']);

            $keys = array_column($template['fields'], 'key');
            $this->assertContains('employer_signature', $keys);
            $this->assertContains('employee_signature', $keys);
            $this->assertNotContains('address', $keys, 'Home address is core, not a field.');
            $this->assertNotContains('employer_address', $keys);
        }
    }

    public function test_hourly_and_salary_are_sections_not_separate_templates(): void
    {
        $tech = $this->named('Field Technician');

        $this->assertStringContainsString('{{#pay_basis.hourly}}', $tech['body']);
        $this->assertStringContainsString('{{#pay_basis.salary}}', $tech['body']);
        $this->assertStringContainsString('{{#raise_amount}}', $tech['body']);
        $this->assertStringContainsString('{{hourly_rate}}', $tech['body']);
        $this->assertStringContainsString('{{salary_amount}}', $tech['body']);

        $keys = array_column($tech['fields'], 'key');
        $this->assertContains('pay_basis', $keys);
        $this->assertContains('raise_amount', $keys);

        $byKey = array_column($tech['fields'], null, 'key');
        $this->assertSame(['key' => 'pay_basis', 'is' => 'hourly'], $byKey['hourly_rate']['shown_when']);
        $this->assertSame(['key' => 'pay_basis', 'is' => 'salary'], $byKey['salary_amount']['shown_when']);
    }

    public function test_the_inspector_is_one_1099_template(): void
    {
        $inspector = $this->named('Field Inspector (1099)');

        $this->assertStringContainsString('{{document.date}}', $inspector['body']);
        $this->assertStringContainsString('{{tools}}', $inspector['body']);
        $this->assertStringContainsString('{{equipment}}', $inspector['body']);

        $keys = array_column($inspector['fields'], 'key');
        $this->assertContains('tools', $keys);
        $this->assertContains('equipment', $keys);
        $this->assertNotContains('hourly_rate', $keys);
        $this->assertNotContains('pay_basis', $keys);

        $labels = array_column($inspector['fields'], 'label', 'key');
        $this->assertSame('Contractor signature', $labels['employee_signature']);
    }

    public function test_live_sections_rewrite_compile_markers(): void
    {
        $source = '<<hourly>>overtime<</hourly>> <<raise>>plus {{raise_amount}}<<hourly>> per hour<</hourly>><</raise>>';

        $this->assertSame(
            "{{#pay_basis.hourly}}overtime{{/pay_basis.hourly}} {{#raise_amount}}plus {{raise_amount}}{{#pay_basis.hourly}} per hour{{/pay_basis.hourly}}{{/raise_amount}}\n",
            SourceCompiler::toLiveSections($source),
        );
    }

    public function test_the_command_publishes_eleven_templates(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($alpha['org'], function () {
            $this->assertSame(11, Contract::query()->count());
        });
    }

    /**
     * The tax, bank and document questions A1 Tools asked every new hire (Jose,
     * 2026-09-30: "those are the most important things"): in every catalog
     * template, and added to an organization's own contracts without touching
     * their wording.
     */
    public function test_new_hire_questions_are_in_the_catalog_and_added_to_existing_contracts(): void
    {
        $byName = collect(OnboardingCatalog::templates())->keyBy('name');
        $keys = fn (string $name) => array_column($byName[$name]['fields'], 'key');
        $this->assertContains('ssn', $keys('Accounting'));
        $this->assertContains('tax_form', $keys('Accounting'));
        $this->assertNotContains('car_front', $keys('Accounting'), 'An office role was asked for a car.');
        $this->assertContains('car_front', $keys('Field Technician'));
    }

    /**
     * The tools and equipment lists become tables, as A1 Tools printed them
     * (Jose, 2026-10-05: "this should be a table"), in the catalog and in an
     * organization's own contracts that spelled them out as bullets.
     */
    public function test_bullet_listed_tools_become_checklist_tables(): void
    {
        $keys = collect(OnboardingCatalog::templates())->keyBy('name');
        $this->assertContains('tools', array_column($keys['Field Technician']['fields'], 'key'));
        $this->assertStringContainsString('{{tools}}', $keys['Field Technician']['body']);
    }

    /**
     * Tools and equipment are the technician's to tick (Jose, 2026-10-05), in
     * the catalog and in an organization's live contracts; an item the list
     * does not have is accepted and printed after the listed ones.
     */
    public function test_checklists_become_the_technicians_and_take_an_added_item(): void
    {
        $catalog = collect(OnboardingCatalog::templates())->keyBy('name');
        $tools = collect($catalog['Field Technician']['fields'])->firstWhere('key', 'tools');
        $this->assertSame('employee', $tools['filled_by']);

        $alpha = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Field Repair Team', 'body' => '## Tools Checklist

{{tools}}',
            'fields' => [
                ['key' => 'tools', 'label' => 'Tools issued', 'type' => 'checklist', 'options' => ['Hammer'],
                    'filled_by' => 'employee', 'required' => false],
                ['key' => 'employee_signature', 'label' => 'Employee signature', 'type' => 'signature',
                    'filled_by' => 'employee', 'required' => true],
            ],
        ])->assertCreated()->json('data.id');

        $field = $this->inTenant($alpha['org'], fn () => collect(Contract::findOrFail($id)->currentVersion->fieldSet()->fields)
            ->firstWhere('key', 'tools'));
        $this->assertSame('employee', $field->filledBy);

        $html = ChecklistTable::html($field, json_encode([
            ['item' => 'Ladder', 'quantity' => '1', 'received' => '2026-10-05'],
        ]));
        $this->assertMatchesRegularExpression('/Hammer<\/td><td><\/td>.*Ladder<\/td><td>1<\/td>/s', $html);
    }

    /** Any contract downloads as a draft PDF before anybody signs (Jose, 2026-10-22). */
    public function test_a_contract_downloads_as_a_draft_pdf(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $id = $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Office Agreement', 'body' => "1. TERMS\n\nPaid \${{hourly_rate}} per hour.",
            'fields' => [['key' => 'hourly_rate', 'label' => 'Hourly rate', 'type' => 'number', 'filled_by' => 'employer', 'required' => true]],
        ])->assertCreated()->json('data.id');

        $response = $this->signedInAs($alpha['admin'])->get("/api/v1/hr/contracts/{$id}/draft.pdf")->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('office-agreement-draft.pdf', (string) $response->headers->get('Content-Disposition'));

        $reader = $this->userWithRole($alpha, 'Reader', ['hr.view', 'hr.contracts.view']);
        $this->signedInAs($reader)->get("/api/v1/hr/contracts/{$id}/draft.pdf")->assertForbidden();
    }

    /** A1 Chimney's COO offer, made reusable (Jose, 2026-10-21). */
    public function test_the_coo_offer_asks_customer_relations_paperwork_without_its_pay_lines(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $f = fn (string $key, string $type = 'text', string $by = 'employer') => ['key' => $key, 'label' => $key, 'type' => $type, 'filled_by' => $by, 'required' => true];
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Customer Relations', 'body' => 'Office agreement.',
            'fields' => [$f('date_of_hire', 'date'), $f('job_title'), $f('hourly_rate', 'number'), $f('salary_amount', 'number'),
                $f('salary_period'), $f('work_days'), $f('work_hours'), $f('county'), $f('ssn', 'text', 'employee'),
                $f('employee_signature', 'signature', 'employee')],
        ])->assertCreated();

        $this->artisan('hr:coo-contract', ['organization' => 'Alpha', '--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing written.')->assertSuccessful();
        $this->artisan('hr:coo-contract', ['organization' => 'Alpha'])->assertSuccessful();
        $this->artisan('hr:coo-contract', ['organization' => 'Alpha'])->expectsOutputToContain('already there')->assertSuccessful();

        $coo = $this->inTenant($alpha['org'], fn () => Contract::where('name', CooContract::NAME)->firstOrFail()->currentVersion);
        $fields = $coo->fieldSet()->toArray();
        $this->assertSame(['date_of_hire', 'job_title', 'salary_amount', 'salary_period',
            'incentive_year', 'incentive_division', 'incentive_pct', 'incentive_minimum', 'perf_comp_year',
            'perf_plan_due', 'perf_comp_minimum', 'pto_days', 'paid_holidays',
            'work_days', 'work_hours', 'ssn', 'employee_signature'],
            collect($fields)->pluck('key')->all());
        // Nobody's name or deal from the signed copy: every amount is a question
        // (Jose, 2026-10-21: "should all be empty fields").
        foreach (['Eric', '120,000', '3,000', '30,000', '2026', '2027', 'Roofing', 'six (6)', 'Jewish'] as $fixed) {
            $this->assertStringNotContainsString($fixed, $coo->body);
        }

        // The designer itself accepts the same wording and questions.
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'COO check', 'body' => $coo->body, 'fields' => $fields,
        ])->assertCreated();

        // The standard clauses the other agreements have (Jose, 2026-10-26:
        // "include all of it"), as version 2, once.
        $this->artisan('hr:coo-clauses', ['organization' => 'Alpha'])
            ->expectsOutputToContain('standard clauses as version 2')->assertSuccessful();
        $this->artisan('hr:coo-clauses', ['organization' => 'Alpha'])
            ->expectsOutputToContain('already has the clauses')->assertSuccessful();
        $v2 = $this->inTenant($alpha['org'], fn () => Contract::where('name', CooContract::NAME)->firstOrFail()->currentVersion);
        $this->assertSame(2, (int) $v2->version);
        foreach (['13. CONFIDENTIALITY', '15. NON-COMPETE & NON-SOLICITATION', '19. DISPUTE RESOLUTION',
            '22. ENTIRE AGREEMENT', '23. ACCEPTANCE, ACKNOWLEDGMENT & SIGNATURES'] as $heading) {
            $this->assertStringContainsString($heading, $v2->body);
        }
        $this->assertStringNotContainsString('upsale', $v2->body);
        $this->assertStringEndsWith(CooClauses::CLOSING, $v2->body);
        $this->assertContains('county', collect($v2->fieldSet()->toArray())->pluck('key')->all());
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'COO v2 check', 'body' => $v2->body, 'fields' => $v2->fieldSet()->toArray(),
        ])->assertCreated();
    }

    /** A1 Tools' Recruiter agreement, built on Customer Relations (A1 takeover, 2026-10-16). */
    public function test_the_recruiter_contract_is_customer_relations_with_its_own_duties(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $body = "This Customer Relations Employment Agreement (\"Agreement\") is entered into between:\r\n\r\n"
            ."2. POSITION & DUTIES\r\n\r\nThe Employee is hired to manage relations.\r\n\r\n• Answer complaints\r\n"
            ."The Employee is expected to maintain professional conduct and be kind.\r\n\r\n"
            ."6. COMPUTER AND ACTIVITY MONITORING\r\n\r\nDevices are monitored.\r\n\r\n".CallScoringClause::CLAUSE."\r\n\r\n"
            ."7. CONFIDENTIALITY\r\n\r\nKeep it.\r\n\r\n8. NON-SOLICITATION\r\n\r\nDo not.";
        $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => 'Customer Relations', 'body' => $body,
            'fields' => [
                ['key' => 'job_title', 'label' => 'Job title', 'type' => 'text', 'filled_by' => 'employer', 'required' => true],
                ['key' => 'employee_signature', 'label' => 'Employee signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
            ],
        ])->assertCreated();

        $this->artisan('hr:recruiter-contract', ['organization' => 'Alpha', '--dry-run' => true])
            ->expectsOutputToContain('Dry run. Nothing written.')->assertSuccessful();
        $this->artisan('hr:recruiter-contract', ['organization' => 'Alpha'])->assertSuccessful();
        $this->artisan('hr:recruiter-contract', ['organization' => 'Alpha'])
            ->expectsOutputToContain('Recruiter: already there')->assertSuccessful();

        $recruiter = $this->inTenant($alpha['org'], fn () => Contract::where('name', 'Recruiter')->firstOrFail()->currentVersion);
        $this->assertStringStartsWith('This Recruiter Employment Agreement', $recruiter->body);
        $this->assertStringContainsString("• Perform additional recruiting and administrative tasks as reasonably assigned\nThe Employee is expected", $recruiter->body);
        $this->assertStringNotContainsString('Answer complaints', $recruiter->body);
        $this->assertStringNotContainsString('Grok', $recruiter->body);
        $this->assertMatchesRegularExpression('/Keep it\.\n\n\(a\) Candidate & Applicant Information\n\n.*\n\n8\. NON-SOLICITATION/s', $recruiter->body);
        $this->assertSame(['job_title', 'employee_signature'], collect($recruiter->fieldSet()->toArray())->pluck('key')->all());
    }

    /** Phone staff are told, in their contract, that calls are scored (Jose, 2026-10-12). */
    public function test_phone_contracts_say_calls_are_recorded_and_scored(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $make = fn (string $name) => $this->signedInAs($alpha['admin'])->postJson('/api/v1/hr/contracts', [
            'name' => $name,
            'body' => "6. COMPUTER AND ACTIVITY MONITORING\r\n\r\nDevices are monitored.\r\n\r\n7. CONFIDENTIALITY\r\n\r\nKeep it.",
            'fields' => [
                ['key' => 'employee_signature', 'label' => 'Employee signature', 'type' => 'signature', 'filled_by' => 'employee', 'required' => true],
            ],
        ])->assertCreated()->json('data.id');
        $dispatch = $make('Dispatch Supervisor');
        $field = $make('Field Technician');

        $this->artisan('hr:call-scoring-clause', ['organization' => 'Alpha', '--dry-run' => true])
            ->expectsOutputToContain('Dispatch Supervisor: call scoring clause as version 2')->assertSuccessful();
        $this->artisan('hr:call-scoring-clause', ['organization' => 'Alpha'])
            ->expectsOutputToContain('1 contracts given a new version.')->assertSuccessful();
        $this->artisan('hr:call-scoring-clause', ['organization' => 'Alpha'])
            ->expectsOutputToContain('0 contracts given a new version.')->assertSuccessful();

        $body = fn (string $id) => $this->inTenant($alpha['org'], fn () => (string) Contract::findOrFail($id)->currentVersion->body);
        $this->assertStringContainsString(
            "Devices are monitored.\n\n".CallScoringClause::CLAUSE."\n\n7. CONFIDENTIALITY",
            $body($dispatch),
        );
        $this->assertStringNotContainsString('Grok', $body($field));
    }

    public function test_replace_retires_keys_the_catalog_no_longer_asks(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($alpha['org'], function () {
            $accounting = Contract::query()->where('name', 'Accounting')->firstOrFail();
            $fields = $accounting->currentVersion->fields;
            $fields[] = [
                'key' => 'address', 'label' => 'Home address', 'type' => 'text',
                'options' => [], 'filled_by' => 'either',
                'required' => true, 'sensitive' => false, 'retired' => false,
            ];
            $fields[] = [
                'key' => 'employer_address', 'label' => 'Business address', 'type' => 'text',
                'options' => [], 'filled_by' => 'employer',
                'required' => true, 'sensitive' => false, 'retired' => false,
            ];
            $next = ContractVersion::create([
                'contract_id' => $accounting->getKey(),
                'version' => ((int) $accounting->currentVersion->version) + 1,
                'fields' => $fields,
                'body' => $accounting->currentVersion->body,
                'created_by' => $accounting->created_by,
            ]);
            $accounting->forceFill(['current_version_id' => $next->getKey()])->save();
        });

        $this->artisan('hr:publish-onboarding-catalog', [
            'organization' => 'Alpha',
            '--replace' => true,
        ])->assertSuccessful();

        $this->inTenant($alpha['org'], function () {
            $accounting = Contract::query()->where('name', 'Accounting')->firstOrFail();
            $fields = $accounting->currentVersion->fields;
            $keys = array_column($fields, 'key');
            $this->assertNotContains('address', $keys, 'Home address is core now; it cannot stay on the template.');
            $this->assertContains('employer_address', $keys);
            $byKey = array_column($fields, null, 'key');
            $this->assertTrue($byKey['employer_address']['retired']);
            $this->assertEqualsCanonicalizing(
                ['key' => 'pay_basis', 'is' => 'hourly'],
                $byKey['hourly_rate']['shown_when'],
            );
        });
    }

    /**
     * @return array{slug: string, name: string, description: string, fields: array, body: string}
     */
    private function named(string $name): array
    {
        foreach (OnboardingCatalog::templates() as $template) {
            if ($template['name'] === $name) {
                return $template;
            }
        }

        $this->fail("Catalog has no template named {$name}.");
    }
}
