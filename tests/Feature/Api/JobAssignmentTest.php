<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Task 5: whose trade a job is, who did it, and when the money was earned
 * (spec §6, §8.1, §14).
 *
 * ── The brief's PATCH to `/api/v1/settings/organization` is not a route ──
 *
 * Same finding JobEarnedStatusesTest already made about a different brief: the
 * real endpoint is `PATCH /api/v1/settings/{key}`, and it requires
 * `scope_type`/`scope_id` on the query string (SettingsController::resolveScope()).
 * Every test below that touches `jobs.earned_statuses` uses that shape.
 */
class JobAssignmentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Set by aJob(), so jobRow() can read back inside the same tenant without
     * every call site having to pass it again.
     *
     * @var array{org: \App\Models\Organization, admin: User, company: \App\Models\Company, franchise: \App\Models\Franchise}|null
     */
    private ?array $tenant = null;

    public function test_a_job_names_a_role_and_the_role_does_not_gate_it(): void
    {
        $alpha  = $this->provisionTenant('Alpha');
        $roleId = $this->aRoleNamed($alpha, 'Chimney Technician');
        $jobId  = $this->aJob($alpha);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['assigned_role_id' => $roleId])
            ->assertOk()
            ->assertJsonPath('data.assigned_role.name', 'Chimney Technician');

        // Somebody whose role is NOT the assigned one still opens it: naming a
        // role says whose trade this is, not who may look.
        $other = $this->aUserWithRole($alpha, 'Inspector', ['jobs.view']);

        $this->signedInAs($other)
            ->getJson("/api/v1/jobs/{$jobId}")
            ->assertOk();
    }

    public function test_a_role_from_another_organization_is_refused(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $foreign = $this->aRoleNamed($beta, 'Their Role');
        $jobId   = $this->aJob($alpha);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['assigned_role_id' => $foreign])
            ->assertStatus(422);
    }

    /**
     * `earned_at` is a MySQL TIMESTAMP — one-second resolution — and this
     * whole scenario can run in under a second. Without a controlled clock, a
     * re-stamp bug would round to the same second as the first stamp and this
     * test would pass for the wrong reason (confirmed: it did, the first time
     * this was written, against a version of the controller with the
     * `earned_at === null` guard deliberately removed). `Carbon::setTestNow()`
     * puts a full day between the two stamps so a re-stamp cannot hide.
     */
    public function test_earned_at_is_stamped_once_on_the_first_earned_transition(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id='
                .$alpha['org']->id, ['value' => ['completed']])
            ->assertOk();

        $jobId = $this->aJob($alpha);

        $this->assertNull($this->jobRow($jobId)->earned_at);

        try {
            Carbon::setTestNow(Carbon::parse('2026-01-10 09:00:00'));

            $this->signedInAs($alpha['admin'])
                ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'completed'])->assertOk();

            $first = $this->jobRow($jobId)->earned_at;
            $this->assertNotNull($first);

            // Out of earned and back in again does NOT re-stamp: the money was
            // earned when the work was first finished, and a later correction
            // must not move a closed month. A full day later, so a re-stamp
            // cannot round back to the same second as the first one.
            Carbon::setTestNow(Carbon::parse('2026-01-11 09:00:00'));

            $this->signedInAs($alpha['admin'])
                ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'scheduled'])->assertOk();
            $this->signedInAs($alpha['admin'])
                ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'completed'])->assertOk();

            $this->assertEquals($first, $this->jobRow($jobId)->earned_at);
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * `job.completed`'s condition checks the OLD status was not earned as well
     * as the new one being earned — not just "is the new status earned". Two
     * earned statuses in a row (an earned-to-earned move, never having left the
     * earned set) must not raise it a second time, which a naive
     * "isEarned(new)" condition would get wrong.
     */
    public function test_job_completed_does_not_fire_again_between_two_earned_statuses(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id='
                .$alpha['org']->id, ['value' => ['in_progress', 'completed']])
            ->assertOk();

        $jobId = $this->aJob($alpha);

        // scheduled (not earned) -> in_progress (earned): fires.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'in_progress'])->assertOk();

        // in_progress (already earned) -> completed (still earned): must NOT
        // fire again — the job never left the earned set.
        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['status' => 'completed'])->assertOk();

        $count = $this->inTenant($alpha['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'job.completed')
            ->where('subject_id', $jobId)
            ->count());

        $this->assertSame(1, $count);
    }

    /**
     * `performed_by_user_id`'s `exists` rule only proves the id is a real
     * user in this organization — it says nothing about reach (§4). This is
     * NOT the cross-tenant case (test_a_role_from_another_organization_is_refused's
     * sibling would be a foreign-org user, which `existsInTenant` already
     * refuses with 422 on its own); this user is in the same organization,
     * just assigned only to a franchise other than the job's.
     */
    public function test_naming_a_performer_who_cannot_reach_the_jobs_franchise_is_refused(): void
    {
        $alpha     = $this->provisionTenant('Alpha');
        $elsewhere = $this->aFranchise($alpha, 'Alpha F2');

        $outOfReach = $this->userWithRole($alpha, 'Technician', [], onlyAt: $elsewhere);

        $jobId = $this->aJob($alpha); // belongs to $alpha['franchise'], not $elsewhere

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['performed_by_user_id' => $outOfReach->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.performed_by_user_id.0',
                'That person is not assigned anywhere this job can be seen.');
    }

    /**
     * The other half of the same check: somebody who genuinely CAN reach the
     * job's franchise must still be nameable. A reach check that refuses
     * everybody would pass the test above on its own — this is what proves
     * it does not.
     */
    public function test_naming_a_performer_who_can_reach_the_jobs_franchise_succeeds(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $inReach = $this->userWithRole($alpha, 'Technician', [], onlyAt: $alpha['franchise']);

        $jobId = $this->aJob($alpha);

        $this->signedInAs($alpha['admin'])
            ->patchJson("/api/v1/jobs/{$jobId}", ['performed_by_user_id' => $inReach->id])
            ->assertOk()
            ->assertJsonPath('data.performed_by.name', 'Technician');
    }

    // ── helpers ──────────────────────────────────────────────────

    private function aFranchise(array $tenant, string $name): Franchise
    {
        return $this->inTenant($tenant['org'], fn () => Franchise::create([
            'organization_id' => $tenant['org']->id,
            'company_id'      => $tenant['company']->id,
            'name'            => $name,
        ]));
    }

    private function aRoleNamed(array $tenant, string $name): string
    {
        return $this->inTenant($tenant['org'], fn () => Role::create([
            'organization_id' => $tenant['org']->id,
            'name'            => $name,
            'is_system'       => false,
        ])->getKey());
    }

    /** Model: Tests\Concerns\BuildsTenants::userWithRole(), which every other test builds a non-admin user from. */
    private function aUserWithRole(array $tenant, string $roleName, array $permissionKeys = []): User
    {
        return $this->userWithRole($tenant, $roleName, $permissionKeys);
    }

    private function aJob(array $tenant): string
    {
        $this->tenant = $tenant;

        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $customer = Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number'              => 'C-'.bin2hex(random_bytes(3)),
                'name'                => 'A Customer',
            ]);

            return Job::create([
                'franchise_id' => $tenant['franchise']->id,
                'customer_id'  => $customer->id,
                'number'       => 'J-'.bin2hex(random_bytes(3)),
                'title'        => 'A job',
                'status'       => 'scheduled',
            ])->getKey();
        });
    }

    private function jobRow(string $id): Job
    {
        return $this->inTenant($this->tenant['org'], fn () => Job::withoutGlobalScopes()->findOrFail($id));
    }
}
