<?php

namespace Tests\Feature\Hr;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Support\Contracts\ChecklistTable;
use App\Support\Contracts\MergeTags;
use App\Support\Hr\OnboardingCatalog;
use App\Support\Hr\OnboardingCatalog\SourceCompiler;
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
