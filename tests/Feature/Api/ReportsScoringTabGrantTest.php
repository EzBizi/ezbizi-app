<?php

namespace Tests\Feature\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reports › Scoring is for Administrator and Lead Manager only (Jose,
 * 2026-10-13: "right now only administrators and lead manager"): the grant
 * reaches the roles named Lead Manager and nobody else.
 */
class ReportsScoringTabGrantTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_only_lead_manager_is_granted_and_the_administrator_already_holds_it(): void
    {
        $t = $this->provisionTenant('Hotel');
        $lead = $this->userWithRole($t, 'Lead Manager', ['reports.view']);
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['reports.view']);

        $holds = fn ($user) => app(TenantContext::class)->run(
            $t['org'], fn () => $user->fresh()->hasPermission('reports.scoring.view'),
        );
        $this->assertFalse($holds($lead));

        (require database_path('migrations/2026_10_13_190000_granting_lead_manager_the_reports_scoring_tab.php'))->up();

        $this->assertTrue($holds($lead));
        $this->assertFalse($holds($dispatcher), 'A role not named Lead Manager was granted Scoring.');
        $this->assertTrue($holds($t['admin']));
    }
}
