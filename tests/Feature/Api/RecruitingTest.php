<?php

namespace Tests\Feature\Api;

use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The applicant pipeline behind the Recruiting report (Shoval, 2026-11-20). */
class RecruitingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function withPositions(array $t): void
    {
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('hr.recruiting.positions', $t['org'], [
            ['value' => 'technician', 'label' => 'Technician'],
            ['value' => 'dispatcher', 'label' => 'Dispatcher'],
        ]));
    }

    public function test_applicants_move_through_stages_with_interviews_and_spend(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->withPositions($t);
        $hr = $this->signedInAs($t['admin']);

        // The default stages ship; positions are the tenant's list.
        $lists = $hr->getJson('/api/v1/hr/applicants')->assertOk()->json('lists');
        $this->assertSame(['applied', 'phone_screen', 'in_person_interview', 'offer', 'hired', 'rejected'],
            array_column($lists['stages'], 'value'));

        $hr->postJson('/api/v1/hr/applicants', ['name' => 'Ana', 'position' => 'janitor'])
            ->assertUnprocessable()->assertJsonValidationErrors('position');

        $id = $hr->postJson('/api/v1/hr/applicants', [
            'name' => 'Ana Lopez', 'phone' => '+15551234567', 'position' => 'technician',
            'source' => 'indeed', 'franchise_id' => $t['franchise']->id,
        ])->assertCreated()->assertJsonPath('data.stage', 'applied')->json('data.id');

        // First move answers them; the time is kept for response time.
        $moved = $hr->patchJson("/api/v1/hr/applicants/{$id}", ['stage' => 'phone_screen', 'relevant' => true])
            ->assertOk()->json('data');
        $this->assertNotNull($moved['first_response_at']);
        $this->assertTrue($moved['relevant']);

        $hr->postJson("/api/v1/hr/applicants/{$id}/interviews", ['kind' => 'in_person', 'scheduled_at' => '2026-11-25 10:00'])
            ->assertCreated()->assertJsonPath('data.kind', 'in_person');
        $hr->postJson("/api/v1/hr/applicants/{$id}/interviews", ['kind' => 'zoom', 'scheduled_at' => '2026-11-25 10:00'])
            ->assertUnprocessable();
        $this->assertCount(1, $hr->getJson("/api/v1/hr/applicants/{$id}")->assertOk()->json('data.interviews'));

        $hr->postJson('/api/v1/hr/recruiting-spend', ['month' => '2026-11', 'position' => 'technician', 'source' => 'indeed',
            'amount' => 450, 'franchise_id' => $t['franchise']->id])->assertCreated()->assertJsonPath('data.month', '2026-11');
        $this->assertSame('450.00', $hr->getJson('/api/v1/hr/recruiting-spend?from=2026-11&to=2026-11')->json('data.0.amount'));

        // Counts over the filtered set, and hires for cost per hire.
        $hr->patchJson("/api/v1/hr/applicants/{$id}", ['stage' => 'hired'])->assertOk();
        $meta = $hr->getJson('/api/v1/hr/applicants?position=technician')->json('meta');
        $this->assertSame([1, 1, 1], [$meta['relevant'], $meta['interviewed'], $meta['hired']]);
        $this->assertIsInt($meta['median_first_response_minutes']);
        $this->assertSame(['technician' => 1], $hr->getJson('/api/v1/hr/recruiting-spend?from=2026-01&to=2030-12')->json('meta.hires'));
        $hr->patchJson("/api/v1/hr/applicants/{$id}", ['stage' => 'phone_screen'])->assertOk();

        // Filters.
        $this->assertCount(1, $hr->getJson('/api/v1/hr/applicants?position=technician&stage=phone_screen')->json('data'));
        $this->assertCount(0, $hr->getJson('/api/v1/hr/applicants?position=dispatcher')->json('data'));
    }

    public function test_a_branch_sees_its_own_applicants_and_hr_view_cannot_write(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->withPositions($t);
        $other = $this->extraFranchise($t, 'Elsewhere');

        $theirs = $this->signedInAs($t['admin'])->postJson('/api/v1/hr/applicants', [
            'name' => 'Bo', 'position' => 'technician', 'franchise_id' => $other->id,
        ])->assertCreated()->json('data.id');

        $branchHr = $this->userWithRole($t, 'Branch HR', ['hr.view', 'hr.edit'], onlyAt: $t['franchise']);
        $this->signedInAs($branchHr)->getJson("/api/v1/hr/applicants/{$theirs}")->assertNotFound();
        $this->assertCount(0, $this->signedInAs($branchHr)->getJson('/api/v1/hr/applicants')->json('data'));
        // Not for another branch, and not without one.
        $this->signedInAs($branchHr)->postJson('/api/v1/hr/applicants', ['name' => 'Cy', 'position' => 'technician',
            'franchise_id' => $other->id])->assertUnprocessable();
        $this->signedInAs($branchHr)->postJson('/api/v1/hr/applicants', ['name' => 'Cy', 'position' => 'technician'])
            ->assertUnprocessable();
        $this->signedInAs($branchHr)->postJson('/api/v1/hr/applicants', ['name' => 'Cy', 'position' => 'technician',
            'franchise_id' => $t['franchise']->id])->assertCreated();

        $reader = $this->userWithRole($t, 'Reader', ['hr.view']);
        $this->signedInAs($reader)->getJson('/api/v1/hr/applicants')->assertOk();
        $this->signedInAs($reader)->postJson('/api/v1/hr/applicants', ['name' => 'Di', 'position' => 'technician'])
            ->assertForbidden();
        $this->signedInAs($this->userWithRole($t, 'Nobody', ['customers.view']))
            ->getJson('/api/v1/hr/applicants')->assertForbidden();
    }
}
