<?php

namespace Tests\Feature\Hr;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** HR findings from the full-code audit (2026-11-27). */
class HrAuditFixesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_lower_hr_editor_cannot_switch_off_an_administrator(): void
    {
        $t = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($t, 'Editor', ['hr.view', 'hr.edit', 'hr.records.view']);
        $tech = $this->userWithRole($t, 'Technician', ['hr.view']);
        // A second administrator, so the last-administrator floor cannot be
        // what refuses: only the role rule can.
        $second = $this->userWithRole($t, 'Spare', ['hr.view'], 'second-admin@alpha.test');
        $this->inTenant($t['org'], fn () => $second->forceFill(['role_id' => $t['admin']->role_id])->save());

        $this->signedInAs($editor)->patchJson("/api/v1/hr/people/{$t['admin']->id}", ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertTrue((bool) $t['admin']->fresh()->is_active);

        // The control: somebody they could have hired is still theirs to edit.
        $this->signedInAs($editor)->patchJson("/api/v1/hr/people/{$tech->id}", ['phone' => '(203) 555-0100'])->assertOk();
    }

    public function test_the_payroll_csv_defuses_a_formula_in_a_name(): void
    {
        $t = $this->provisionTenant('Alpha');
        $person = $this->userWithRole($t, 'Technician', ['hr.view']);
        $this->inTenant($t['org'], fn () => $person->forceFill(['name' => '=HYPERLINK("http://evil","x")'])->save());

        $csv = $this->signedInAs($t['admin'])->get('/api/v1/hr/payroll?format=csv')->assertOk()->getContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
    }
}
