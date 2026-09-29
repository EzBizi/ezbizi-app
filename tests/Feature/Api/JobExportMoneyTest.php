<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The bulk export is a way out for job money too (Jose, 2026-09-29: a
 * financials toggle). Without `jobs.financials` the jobs export carries no
 * amount and no currency; with it, it carries both.
 */
class JobExportMoneyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_job_export_has_no_money_without_the_toggle(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aPricedJob($alpha);
        $exporter = $this->userWithRole($alpha, 'Exporter', ['jobs.view', 'jobs.export']);

        $header = $this->header($exporter);

        $this->assertStringContainsString('number', $header, 'The control: this IS the header row.');
        $this->assertStringNotContainsString('amount_minor', $header);
        $this->assertStringNotContainsString('currency', $header);
    }

    public function test_the_toggle_puts_the_money_back(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->aPricedJob($alpha);
        $exporter = $this->userWithRole($alpha, 'Exporter', ['jobs.view', 'jobs.export', 'jobs.financials']);

        $this->assertStringContainsString('amount_minor', $this->header($exporter));
    }

    /** The export picker lists what the export will carry, and no more. */
    public function test_the_picker_lists_no_money_columns_without_the_toggle(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $exporter = $this->userWithRole($alpha, 'Exporter', ['jobs.view', 'jobs.export']);

        $jobs = collect($this->asUser($exporter)->getJson('/api/v1/exports')->assertOk()->json('resources'))
            ->firstWhere('key', 'jobs');

        $this->assertContains('number', $jobs['columns']);
        $this->assertNotContains('amount_minor', $jobs['columns']);
    }

    private function header(User $user): string
    {
        $body = $this->asUser($user)->get('/api/v1/exports/jobs')->assertOk()->streamedContent();

        return (string) strtok($body, "\n");
    }

    private function asUser(User $user): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function aPricedJob(array $tenant): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => 'A Customer',
            ]);

            Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id' => $customer->id,
                'number' => 'J-'.bin2hex(random_bytes(3)),
                'title' => 'A job',
                'status' => 'scheduled',
                'amount_minor' => 12345,
                'currency' => 'USD',
            ]);
        });
    }
}
