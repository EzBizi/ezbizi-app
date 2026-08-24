<?php

namespace Tests\Feature\Hr;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Support\Contracts\MergeTags;
use App\Support\Hr\OnboardingCatalog;
use App\Support\Hr\OnboardingCatalog\SourceCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'version'     => ((int) $accounting->currentVersion->version) + 1,
                'fields'      => $fields,
                'body'        => $accounting->currentVersion->body,
                'created_by'  => $accounting->created_by,
            ]);
            $accounting->forceFill(['current_version_id' => $next->getKey()])->save();
        });

        $this->artisan('hr:publish-onboarding-catalog', [
            'organization' => 'Alpha',
            '--replace'    => true,
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
