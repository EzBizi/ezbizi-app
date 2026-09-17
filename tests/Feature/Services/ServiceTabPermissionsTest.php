<?php

namespace Tests\Feature\Services;

use App\Models\ServiceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Services' two chips as grants — Catalogue and Settings (Jose, 2026-09-17).
 *
 * ── The asymmetry this suite exists to protect ───────────────────
 *
 * The chips gate the WRITES and deliberately not the reads, because both read
 * paths serve an audience outside the chip that would otherwise gate them:
 *
 *  - `GET services` is the catalogue Jobs prices a line out of, in
 *    client/lib/screens/jobs/job_lines_tab.dart. A dispatcher who never opens
 *    the Services screen still has to read it.
 *  - `GET services/groups` and `GET services/labels` are read by the CATALOGUE
 *    chip's own pickers as much as by Settings.
 *
 * Gating either would refuse somebody doing a job they are entitled to do, and
 * would do it as an empty menu rather than an error. The last two tests here
 * are the regression guard on that, and they are the reason this file exists at
 * all — the 403s are the easy half.
 *
 * Every test is about the SERVER. A hidden chip whose endpoints still answer is
 * a decoration; the client filtering is asserted in services_screen_test.dart.
 */
class ServiceTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{0: array, 1: \App\Models\User} */
    private function tenantWith(array $permissions): array
    {
        $tenant = $this->provisionTenant('Alpha');

        return [$tenant, $this->userWithRole($tenant, 'Limited', $permissions)];
    }

    private function newService(array $tenant): array
    {
        return [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Chimney sweep',
            'price_minor' => 18900,
        ];
    }

    // ── Positive controls, written first ─────────────────────────
    //
    // Without these a wall of 403s proves only that something refuses, which is
    // also what a misconfigured fixture, an unsynced permission or a platform
    // gate answering first would produce.

    public function test_catalogue_granted_can_write_the_catalogue(): void
    {
        [$tenant, $user] = $this->tenantWith([
            'services.view', 'services.catalogue.view', 'services.create',
        ]);

        $this->signedInAs($user)
            ->postJson('/api/v1/services', $this->newService($tenant))
            ->assertCreated();
    }

    public function test_settings_granted_can_write_the_taxonomy(): void
    {
        [$tenant, $user] = $this->tenantWith([
            'services.view', 'services.settings.view', 'services.create',
        ]);

        $this->signedInAs($user)
            ->postJson('/api/v1/services/groups', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney',
            ])
            ->assertCreated();
    }

    // ── The refusals ─────────────────────────────────────────────

    public function test_without_catalogue_the_catalogue_write_is_refused(): void
    {
        [$tenant, $user] = $this->tenantWith([
            'services.view', 'services.settings.view', 'services.create',
        ]);

        $this->signedInAs($user)
            ->postJson('/api/v1/services', $this->newService($tenant))
            ->assertForbidden();
    }

    public function test_without_settings_the_taxonomy_write_is_refused(): void
    {
        [$tenant, $user] = $this->tenantWith([
            'services.view', 'services.catalogue.view', 'services.create',
        ]);

        $this->signedInAs($user)
            ->postJson('/api/v1/services/groups', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'name' => 'Chimney',
            ])
            ->assertForbidden();
    }

    // ── The two-audience guards ──────────────────────────────────

    public function test_neither_chip_still_reads_the_catalogue_for_jobs(): void
    {
        [, $user] = $this->tenantWith(['services.view']);

        $this->signedInAs($user)
            ->getJson('/api/v1/services')
            ->assertOk();
    }

    public function test_catalogue_alone_still_reads_the_group_and_label_lists(): void
    {
        [$tenant, $user] = $this->tenantWith([
            'services.view', 'services.catalogue.view',
        ]);

        // A real row, so an empty list cannot be mistaken for a working read.
        $this->inTenant($tenant['org'], fn () => ServiceGroup::create([
            'company_id' => $tenant['company']->id,
            'franchise_id' => $tenant['franchise']->id,
            'name' => 'Chimney',
        ]));

        $this->signedInAs($user)
            ->getJson('/api/v1/services/groups')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Chimney');

        $this->signedInAs($user)
            ->getJson('/api/v1/services/labels')
            ->assertOk();
    }
    // ── Nobody loses a chip they had this morning ────────────────

    /**
     * The 090000 sweep was spent before Services declared its chips, so this
     * module ships its own re-run. RefreshDatabase applies migrations to an
     * empty database, where there is no role to widen — so the class is
     * invoked directly, against a role built to look like one that predates
     * the split.
     */
    public function test_a_role_that_only_held_services_view_is_given_both_chips(): void
    {
        [$tenant, $user] = $this->tenantWith(['services.view']);

        $this->inTenant($tenant['org'], function () use ($user) {
            $role = $user->role;

            // The distinguishing assertion: without this the test would pass
            // against a fixture that had been granted the chips all along.
            $this->assertFalse($role->hasPermission('services.catalogue.view'));
            $this->assertFalse($role->hasPermission('services.settings.view'));

            $migration = require base_path(
                'database/migrations/'
                .'2026_09_17_183000_grant_the_services_chips_to_roles_that_had_them.php',
            );
            $migration->up();

            $role = $role->fresh();
            $this->assertTrue($role->hasPermission('services.catalogue.view'));
            $this->assertTrue($role->hasPermission('services.settings.view'));
        });
    }
    /**
     * And nobody GAINS one either — the direction that does not get reported.
     *
     * `TabDefinition::action()` falls back to `"{key}.view"`, so a tab that
     * declares an `action:` of its own names a grant the module ALREADY had.
     * Training is the live case on main: `action: 'view'`, `'monitor'`,
     * `'manage'` are three deliberate grants, and `manage` is the authoring
     * surface. An unguarded sweep would hand it to every learner.
     *
     * Found by the deploy desk before the cut, not by this suite — which is
     * why the assertion is here now: the next person to copy this migration
     * gets a red test instead of a repeat.
     */
    public function test_a_tab_naming_an_existing_action_is_never_widened(): void
    {
        [$tenant, $user] = $this->tenantWith(['services.view', 'training.view']);

        $this->inTenant($tenant['org'], function () use ($user) {
            $role = $user->role;

            $migration = require base_path(
                'database/migrations/'
                .'2026_09_17_183000_grant_the_services_chips_to_roles_that_had_them.php',
            );
            $migration->up();

            $role = $role->fresh();

            // Positive control FIRST: an inert migration — one that widened
            // nothing because it errored, skipped every module, or found no
            // roles — would satisfy the refusals below while doing nothing.
            $this->assertTrue($role->hasPermission('services.catalogue.view'));

            // Training's Learn tab declares `action: 'view'`, so the only
            // training key this role should hold is the one it came in with.
            $this->assertFalse($role->hasPermission('training.monitor'));
            $this->assertFalse($role->hasPermission('training.manage'));
        });
    }
}
