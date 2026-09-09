<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floor plans — the Monitoring "General" tab
 * (docs/superpowers/specs/2026-09-09-monitoring-general-map-design.md).
 *
 * Its own file, following the same rule as every other monitoring migration
 * (see 2026_09_01_150000_create_monitoring_cameras_table.php): once a migration
 * has run anywhere it is spent, so a new table gets a new file with a later
 * timestamp rather than a Schema::create appended to one that already ran.
 *
 * ── Two org columns, and why ─────────────────────────────────────
 *
 * `organization_id` is the TENANT column — the one BelongsToTenant stamps on
 * create and filters reads by (it qualifies `organization_id` literally, the
 * same column monitoring_cameras carries). `assigned_organization_id` is a
 * SCOPE column: it, `assigned_company_id` and `assigned_franchise_id` are the
 * exact shape `assignments`/`invitations` carry, which is what lets
 * ScopeReach::constrain() narrow a plan query to a viewer's Org > Company >
 * Franchise reach with no new predicate.
 *
 * Unlike `assignments` (exactly one of the trio, by CHECK), a floor plan sets
 * `assigned_organization_id` ALWAYS (it is "always the org", stamped by the
 * model) PLUS optionally a company or a franchise. That is deliberate: it is
 * what makes ScopeReach's org branch — `orWhere('assigned_organization_id',
 * org)` — show every plan to an org-level viewer while a company/franchise
 * viewer still matches only through its own assigned_* column. So there is no
 * XOR constraint here, on purpose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_floor_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Tenant column (BelongsToTenant). Same shape as monitoring_cameras.
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // Scope columns — the three ScopeReach reads. assigned_organization_id
            // is always set (the model stamps it to the tenant org); company and
            // franchise are set only for narrower plans.
            $table->foreignUuid('assigned_organization_id')
                ->constrained('organizations')->cascadeOnDelete();
            //
            // cascadeOnDelete, NOT nullOnDelete, and that is the whole point:
            // nulling `assigned_company_id` when a company is deleted would
            // leave `assigned_organization_id` standing alone, which is exactly
            // the shape of an ORGANIZATION-scoped plan - so deleting a company
            // would silently WIDEN its plans to the whole org and show them to
            // everyone. A plan belonging to a deleted company goes with it.
            // `invitations` carries the same trio and cascades all three for
            // the same reason (2026_08_11_000000_create_invitations_table).
            $table->foreignUuid('assigned_company_id')->nullable()
                ->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('assigned_franchise_id')->nullable()
                ->constrained('franchises')->cascadeOnDelete();

            $table->string('name');

            // The whole plan — grid + walls + nodes — edited and saved as a unit.
            $table->json('layout');

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_floor_plans');
    }
};
