<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\Assignment;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Customer → Job vertical slice (plan §18 Phase 2).
 *
 * > "The point isn't the feature — it's proving, end to end, that tenant
 * > isolation, roll-up visibility, permission enforcement, settings resolution,
 * > audit durability and API contracts all actually work together."
 *
 * So this file is the acceptance criteria for the whole foundation, not a CRUD
 * test. Each block below names which of those it is defending.
 */
class VerticalSliceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{org: \App\Models\Organization, admin: User, company: Company, franchise: Franchise} */
    private function provision(string $name, string $email): array
    {
        $result = app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => 'a-real-password'],
            companyName: "{$name} Co",
            franchiseName: "{$name} F1",
        );

        return [
            'org'       => $result['organization'],
            'admin'     => $result['administrator'],
            'company'   => $result['company'],
            'franchise' => $result['franchise'],
        ];
    }

    private function tokenFor(User $user): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => 'a-real-password',
        ])->assertOk()->json('token');
    }

    private function as(User $user): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user));
    }

    /** Run something inside a tenant, the way a job or console command would. */
    private function inTenant(\App\Models\Organization $org, callable $callback): mixed
    {
        return app(TenantContext::class)->run($org, $callback);
    }

    // ── tenant isolation — the highest-severity bug class (§5.1) ──

    public function test_one_organization_cannot_see_or_touch_another_s_customers(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');
        $bravo = $this->provision('Bravo', 'b@bravo.test');

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Alpha Customer', 'phone' => '5551234567',
        ])->assertCreated()->json('data.id');

        // Not in the list...
        $this->as($bravo['admin'])->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // ...and not reachable by id either. A UUID is not an authorization
        // check (§5) — guessing one must not be enough, and neither must
        // stealing one from a log.
        $this->as($bravo['admin'])->getJson("/api/v1/customers/{$customer}")
            ->assertNotFound();
    }

    public function test_a_job_cannot_be_created_against_another_organizations_franchise(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');
        $bravo = $this->provision('Bravo', 'b@bravo.test');

        $customer = $this->inTenant($bravo['org'], fn () => Customer::create([
            'owning_franchise_id' => $bravo['franchise']->id,
            'number' => 'C-1', 'name' => 'Bravo Customer',
        ]));

        // Alpha's administrator naming Bravo's franchise and Bravo's customer.
        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $bravo['franchise']->id,
            'customer_id'  => $customer->id,
            'title' => 'Cross-tenant job', 'status' => 'scheduled',
        ])->assertStatus(422);   // the ids do not exist within Alpha's scope
    }

    // ── sharing vs scoping (§5.2) ────────────────────────────────

    public function test_customers_are_shared_across_franchises_but_jobs_are_not(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        [$second, $secondUser] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $franchise = Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $alpha['company']->id,
                'name'            => 'Second Franchise',
                'is_active'       => true,
            ]);

            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $alpha['admin']->role_id,
                'name' => 'Second', 'email' => 'second@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'      => $alpha['org']->id,
                'user_id'              => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Shared Person', 'phone' => '5550001111',
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $customer,
            'title' => 'First franchise job', 'status' => 'scheduled',
        ])->assertCreated();

        // The customer IS visible to the other franchise — that is what sharing
        // means, and the owning-franchise tag is what keeps it honest.
        $this->as($secondUser)->getJson('/api/v1/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owning_franchise.name', $alpha['franchise']->name);

        // The job is NOT. A job happens at one place with one crew.
        $this->as($secondUser)->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_working_for_another_franchises_customer_adds_served_by_without_moving_the_owner(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Shared Person',
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $second->id,
            'customer_id'  => $customer,
            'title' => 'Job by the other franchise', 'status' => 'scheduled',
        ])->assertCreated();

        $detail = $this->as($alpha['admin'])->getJson("/api/v1/customers/{$customer}")->assertOk();

        // Derived from jobs, never stored — a hand-maintained list goes stale.
        $this->assertContains($second->id, $detail->json('data.served_by_franchise_ids'));
        $this->assertTrue($detail->json('data.shared_in_practice'));

        // And doing the work did NOT quietly take over the relationship (§5.2).
        $this->assertSame(
            $alpha['franchise']->id,
            $detail->json('data.owning_franchise.id')
        );
    }

    // ── roll-up authority (§4) ───────────────────────────────────

    public function test_a_company_level_user_sees_jobs_from_a_sub_companys_franchise(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        [$subFranchise, $manager] = $this->inTenant($alpha['org'], function () use ($alpha) {
            $subCompany = Company::create([
                'organization_id'   => $alpha['org']->id,
                'parent_company_id' => $alpha['company']->id,
                'name' => 'Sub Company', 'is_active' => true,
            ]);

            $franchise = Franchise::create([
                'organization_id' => $alpha['org']->id,
                'company_id'      => $subCompany->id,
                'name' => 'Sub Franchise', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $alpha['admin']->role_id,
                'name' => 'Company Manager', 'email' => 'manager@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            // Assigned to the TOP company only. Assignment does not cascade —
            // authority does, and this is the step most easily missed: a Sub
            // Company's franchises are inside the parent's subtree.
            Assignment::create([
                'organization_id'     => $alpha['org']->id,
                'user_id'             => $user->id,
                'assigned_company_id' => $alpha['company']->id,
            ]);

            return [$franchise, $user];
        });

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $subFranchise->id, 'name' => 'Sub Customer',
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $subFranchise->id, 'customer_id' => $customer,
            'title' => 'Work in the sub company', 'status' => 'scheduled',
        ])->assertCreated();

        $this->as($manager)->getJson('/api/v1/jobs')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_company_level_user_can_write_not_merely_read_beneath_them(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $manager = $this->inTenant($alpha['org'], function () use ($alpha) {
            $user = User::create([
                'organization_id' => $alpha['org']->id,
                'role_id'         => $alpha['admin']->role_id,
                'name' => 'Company Manager', 'email' => 'manager@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'     => $alpha['org']->id,
                'user_id'             => $user->id,
                'assigned_company_id' => $alpha['company']->id,
            ]);

            return $user;
        });

        // §4: "Someone on Serenisima can create a job in Argentina. Write access
        // follows the same downward path as read access." Getting this wrong in
        // the restrictive direction leaves managers seeing work they cannot touch.
        $customer = $this->as($manager)->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $this->as($manager)->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'Created from above', 'status' => 'scheduled',
        ])->assertCreated();
    }

    // ── permission enforcement (§4) ──────────────────────────────

    public function test_a_role_without_the_permission_is_refused(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $viewer = $this->inTenant($alpha['org'], function () use ($alpha) {
            $role = Role::create([
                'organization_id' => $alpha['org']->id,
                'name' => 'Viewer', 'is_system' => false,
            ]);

            // View but not create. Stored per ACTION, always (§4) — the module
            // toggle in the UI is a convenience that sets all four at once.
            foreach (['customers.view', 'jobs.view'] as $key) {
                $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], [
                    'key' => $key, 'module' => explode('.', $key)[0], 'action' => explode('.', $key)[1],
                ])->id);
            }

            $user = User::create([
                'organization_id' => $alpha['org']->id, 'role_id' => $role->id,
                'name' => 'Viewer', 'email' => 'viewer@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'      => $alpha['org']->id,
                'user_id'              => $user->id,
                'assigned_franchise_id' => $alpha['franchise']->id,
            ]);

            return $user;
        });

        $this->as($viewer)->getJson('/api/v1/customers')->assertOk();

        $this->as($viewer)->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Nope',
        ])->assertForbidden();
    }

    // ── settings resolution reaches real data (§6) ───────────────

    public function test_job_numbers_follow_the_numbering_settings_and_a_franchise_override(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        $first = $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'One', 'status' => 'scheduled',
        ])->assertCreated()->json('data.number');

        // Registry defaults: JOB- prefix, padded to five.
        $this->assertSame('JOB-00001', $first);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('numbering.job_prefix', $alpha['franchise'], 'WORK-'));

        $second = $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'Two', 'status' => 'scheduled',
        ])->assertCreated()->json('data.number');

        // Format follows the franchise override; the SEQUENCE stays
        // organization-wide, so the counter carries on rather than restarting.
        $this->assertSame('WORK-00002', $second);
    }

    public function test_a_status_outside_the_organizations_list_is_refused(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        // Permitted values are tenant configuration (§2.1), so this is not an
        // `in:` rule — it is resolved per organization at request time.
        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'Invented status', 'status' => 'levitating',
        ])->assertStatus(422);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('jobs.statuses', $alpha['org'], ['scheduled', 'levitating']));

        $this->as($alpha['admin'])->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer,
            'title' => 'Invented status', 'status' => 'levitating',
        ])->assertCreated();
    }

    // ── audit durability (§10) ───────────────────────────────────

    public function test_creating_a_customer_writes_an_audit_row_in_the_same_transaction(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Audited Person',
        ])->assertCreated();

        $event = AuditEvent::where('action', 'customer.created')->first();

        $this->assertNotNull($event);
        $this->assertSame($alpha['org']->id, $event->organization_id);
        $this->assertSame('Audited Person', $event->target_label);
        $this->assertSame($alpha['admin']->id, $event->actor_id);
    }

    public function test_reassigning_the_owner_records_both_ends(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Mover',
        ])->assertCreated()->json('data.id');

        $this->as($alpha['admin'])->postJson("/api/v1/customers/{$customer}/owner", [
            'owning_franchise_id' => $second->id,
            'reason' => 'Customer relocated to the second franchise territory.',
        ])->assertOk()->assertJsonPath('data.owning_franchise.id', $second->id);

        $event = AuditEvent::where('action', 'customer.owner_reassigned')->first();

        $this->assertNotNull($event);
        // Names as well as ids: a franchise can be renamed later, and "who was
        // accountable in March" still has to be answerable.
        $this->assertSame($alpha['franchise']->name, $event->metadata['from']['name']);
        $this->assertSame($second->name, $event->metadata['to']['name']);
    }

    public function test_a_routine_edit_cannot_move_the_owner_as_a_side_effect(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $customer = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id, 'name' => 'Person',
        ])->assertCreated()->json('data.id');

        // Reassignment is a deliberate, separately authorised act (§5.2).
        $this->as($alpha['admin'])->patchJson("/api/v1/customers/{$customer}", [
            'name' => 'Renamed', 'owning_franchise_id' => $second->id,
        ])->assertStatus(422);
    }

    // ── numbering under concurrency (§5.2) ───────────────────────

    public function test_customer_numbers_are_unique_and_sequential_organization_wide(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $second = $this->inTenant($alpha['org'], fn () => Franchise::create([
            'organization_id' => $alpha['org']->id,
            'company_id'      => $alpha['company']->id,
            'name' => 'Second Franchise', 'is_active' => true,
        ]));

        $numbers = [];

        foreach ([$alpha['franchise']->id, $second->id, $alpha['franchise']->id] as $i => $franchiseId) {
            $numbers[] = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
                'owning_franchise_id' => $franchiseId, 'name' => "Person {$i}",
            ])->assertCreated()->json('data.number');
        }

        // Two franchises numbering from 1 apiece would put two different people
        // called C-00001 in one shared search result (§5.2).
        $this->assertSame(['C-00001', 'C-00002', 'C-00003'], $numbers);
    }

    // ── duplicates are surfaced, never merged (§5.2) ─────────────

    public function test_a_possible_duplicate_is_reported_but_does_not_block(): void
    {
        $alpha = $this->provision('Alpha', 'a@alpha.test');

        $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'First Entry', 'phone' => '(555) 123-4567',
        ])->assertCreated();

        $response = $this->as($alpha['admin'])->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Second Entry', 'phone' => '555-123-4567',
        ])->assertCreated();

        // Same number typed two ways. Reported for a human to judge — automatic
        // merging of customer records is unrecoverable when it is wrong, and a
        // second household on one line is real.
        $this->assertCount(1, $response->json('possible_duplicates'));
        $this->assertSame('First Entry', $response->json('possible_duplicates.0.name'));
    }

    // ── the module registry (§5.5) ───────────────────────────────

    public function test_the_new_modules_appear_with_their_permissions_and_triggers(): void
    {
        $registry = app(\App\Support\Modules\ModuleRegistry::class);

        foreach (['customers', 'jobs'] as $key) {
            $module = $registry->get($key);

            $this->assertNotNull($module, "Module '{$key}' is not registered.");
            $this->assertContains("{$key}.create", $module->permissionKeys());

            // The §5.5 standing rule: a module that cannot participate in
            // automation is not finished.
            $this->assertTrue($module->satisfiesAutomationRule());
            $this->assertNotEmpty($module->triggers);
        }
    }
}
