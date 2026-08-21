<?php

namespace Tests\Feature\Hr;

use App\Models\Contract;
use App\Support\Contracts\MergeTags;
use App\Support\Hr\OnboardingCatalog;
use App\Support\Hr\OnboardingCatalog\SourceCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A-1 role agreements adapted to EzBizi, not the other way around.
 *
 * The catalog must publish: FieldSet accepts every field list, MergeTags
 * accepts every body, and none of A-1's clause-deletion tags survive.
 */
class OnboardingCatalogTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_every_template_is_a_legal_ezbizi_contract(): void
    {
        $templates = OnboardingCatalog::templates();

        $this->assertCount(41, $templates, '10 W-2 roles × 4 pay/raise splits, plus the 1099 inspector.');

        $names = [];

        foreach ($templates as $template) {
            $this->assertNotSame('', $template['name']);
            $this->assertArrayNotHasKey($template['name'], $names, 'Two templates share a name.');
            $names[$template['name']] = true;

            $this->assertLessThanOrEqual(
                MergeTags::MAX_BODY_LENGTH,
                mb_strlen($template['body']),
                $template['name'].' is over the body cap.',
            );

            $this->assertStringNotContainsString('<<', $template['body']);
            $this->assertStringNotContainsString('hourly_only', $template['body']);
            $this->assertStringNotContainsString('raise_only', $template['body']);
            $this->assertStringNotContainsString('raise_unit', $template['body']);
            $this->assertStringNotContainsString('{{name}}', $template['body']);
            $this->assertStringNotContainsString('{{company_name}}', $template['body']);
            $this->assertStringNotContainsString('{{company_address}}', $template['body']);
            $this->assertStringNotContainsString('{{date}}', $template['body']);
            $this->assertStringNotContainsString('{{day}}', $template['body']);
            $this->assertStringNotContainsString('{{month}}', $template['body']);
            $this->assertStringNotContainsString('{{year2}}', $template['body']);

            $keys = array_column($template['fields'], 'key');
            $this->assertContains('employer_signature', $keys);
            $this->assertContains('employee_signature', $keys);
            $this->assertContains('address', $keys);
            $this->assertContains('employer_address', $keys);
        }
    }

    public function test_hourly_and_salary_are_separate_templates_not_switches(): void
    {
        $hourly = $this->named('Field Technician (hourly)');
        $salary = $this->named('Field Technician (salary)');
        $raised = $this->named('Field Technician (hourly, scheduled increases)');

        $this->assertStringContainsString('{{hourly_rate}}', $hourly['body']);
        $this->assertStringContainsString('overtime', strtolower($hourly['body']));
        $this->assertStringNotContainsString('{{salary_amount}}', $hourly['body']);

        $this->assertStringContainsString('{{salary_amount}}', $salary['body']);
        $this->assertStringNotContainsString('{{hourly_rate}}', $salary['body']);
        $this->assertStringNotContainsString('Overtime', $salary['body']);

        $this->assertStringContainsString('{{raise_amount}}', $raised['body']);
        $this->assertStringContainsString(' per hour', $raised['body']);
        $this->assertStringNotContainsString('{{raise_amount}}', $hourly['body']);

        $salaryRaised = $this->named('Field Technician (salary, scheduled increases)');
        $this->assertStringContainsString('{{raise_amount}}', $salaryRaised['body']);
        $this->assertStringNotContainsString(' per hour', $salaryRaised['body']);
    }

    public function test_the_inspector_is_one_1099_template_dated_as_paperwork(): void
    {
        $inspector = $this->named('Field Inspector (1099)');

        $this->assertStringContainsString('{{document.date}}', $inspector['body']);
        $this->assertStringContainsString('{{commission_pct}}', $inspector['body']);
        $this->assertStringContainsString('{{organization.name}}', $inspector['body']);
        $this->assertStringContainsString('{{first_name}} {{last_name}}', $inspector['body']);
        $this->assertStringNotContainsString('{{date_of_hire}}', $inspector['body']);
        $this->assertStringNotContainsString('{{hourly_rate}}', $inspector['body']);

        $keys = array_column($inspector['fields'], 'key');
        $this->assertNotContains('hourly_rate', $keys);
        $this->assertNotContains('date_of_hire', $keys);

        $labels = array_column($inspector['fields'], 'label', 'key');
        $this->assertSame('Contractor signature', $labels['employee_signature']);
        $this->assertSame('Company representative signature', $labels['employer_signature']);
    }

    public function test_nested_raise_unit_compiles_per_hour_only_on_hourly(): void
    {
        $source = 'increase by ${{raise_amount}}<<hourly>> per hour<</hourly>> every {{raise_period}}';

        $this->assertSame(
            "increase by \${{raise_amount}} per hour every {{raise_period}}\n",
            SourceCompiler::compile($source, ['hourly', 'raise']),
        );
        $this->assertSame(
            "increase by \${{raise_amount}} every {{raise_period}}\n",
            SourceCompiler::compile($source, ['salary', 'raise']),
        );
        $this->assertSame(
            "\n",
            SourceCompiler::compile('<<raise>>'.$source.'<</raise>>', ['hourly']),
        );
    }

    public function test_the_command_publishes_into_a_tenant_and_skips_names_that_exist(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $this->assertSame(41, Contract::query()->count());
        });

        $this->artisan('hr:publish-onboarding-catalog', ['organization' => 'Alpha'])
            ->expectsOutputToContain('Created 0, replaced 0, skipped 41')
            ->assertSuccessful();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $this->assertSame(41, Contract::query()->count());
            $this->assertSame(
                1,
                Contract::query()->where('name', 'Field Technician (hourly)')->count(),
            );

            $first = Contract::query()->where('name', 'Call Center Employee (salary)')->first();
            $this->assertNotNull($first);
            $this->assertNotNull($first->currentVersion?->body);
            $this->assertStringContainsString('{{daily_deduction}}', $first->currentVersion->body);
            $this->assertSame($alpha['admin']->getKey(), $first->created_by);
        });
    }

    public function test_dry_run_writes_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->artisan('hr:publish-onboarding-catalog', [
            'organization' => 'Alpha',
            '--dry-run'    => true,
        ])->assertSuccessful();

        $this->inTenant($alpha['org'], function () {
            $this->assertSame(0, Contract::query()->count());
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
