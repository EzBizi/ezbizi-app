<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EzBizi foundation — the hierarchy everything else hangs off.
 *
 *   Organization                          the client
 *   └── Company                           may hold Franchises AND Sub Companies
 *       ├── Franchise                     the operating unit; work happens here
 *       └── Sub Company                   a Company with a parent
 *           └── Franchise
 *
 * Reference: H:\AWS\Application\EZBIZI-BUILD-PLAN.md §3, §4, §5
 *
 * Decisions encoded here, each of which is expensive to change later:
 *   - UUID primary keys       (§5) — sequential ints leak business volume via the API
 *   - organization_id on every tenant-owned row (§5.1) — the tenant boundary
 *   - Sub Company = companies.parent_company_id IS NOT NULL — no separate table,
 *     because a Sub Company behaves identically to a Company (§3)
 *   - Fixed depth, no recursion (§3 answer 1-1) — so plain parent_id suffices;
 *     no nested sets or materialised paths needed
 *   - A user belongs to exactly ONE organization (§4) — separate accounts per org
 *   - Assignments are many-per-user, each targeting exactly one scope (XOR, enforced
 *     by a CHECK constraint, not just by application code)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────────────────────────────────────────────
        // Organization — the tenant root. Everything traces back here.
        // ─────────────────────────────────────────────────────────────
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();

            // §7 — single currency in v1, schema ready for multi-currency
            $table->char('reporting_currency', 3)->default('USD');

            // §8 — clients may rename "Franchise" to Location / Branch / Region
            $table->string('franchise_label')->default('Franchise');

            // §5.2 — customer sharing across franchises. ON per owner decision,
            // but per-organization because franchise networks must be able to seal it.
            $table->boolean('shares_customers')->default(true);

            // §13 — subscription lifecycle
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('is_active')->default(true);

            // demo orgs are resettable and must never be mistaken for real ones (§12)
            $table->boolean('is_demo')->default(false);

            $table->timestamps();
            $table->softDeletes();
        });

        // ─────────────────────────────────────────────────────────────
        // Company — and Sub Company, which is the same thing with a parent.
        // ─────────────────────────────────────────────────────────────
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();

            // NULL  => Company
            // set   => Sub Company (§3: a company in its own right, with a parent)
            $table->foreignUuid('parent_company_id')->nullable()
                  ->constrained('companies')->nullOnDelete();

            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'parent_company_id']);
        });

        // ─────────────────────────────────────────────────────────────
        // Franchise — the operating unit. Business data lives at this level (§5).
        // ─────────────────────────────────────────────────────────────
        Schema::create('franchises', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Denormalised from companies deliberately: every tenant-owned table
            // carries organization_id so the boundary is one predicate, never a join.
            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')
                  ->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('timezone')->default('America/New_York');   // §8, per-franchise
            $table->char('currency', 3)->default('USD');               // §7, per-franchise
            $table->boolean('is_active')->default(true);               // §5: deactivate ≠ delete
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'company_id']);
        });

        // ─────────────────────────────────────────────────────────────
        // Permissions — a PLATFORM catalogue, not tenant data.
        // Deliberately has no organization_id: the set of possible actions is
        // defined by the software, not by clients (§2.1 — platform invariant).
        // ─────────────────────────────────────────────────────────────
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();     // 'jobs.create'
            $table->string('module');            // 'jobs'
            $table->string('action');            // 'create'
            $table->string('label');
            $table->timestamps();

            $table->index('module');
        });

        // ─────────────────────────────────────────────────────────────
        // Roles — organization-global (§4). Scope comes from assignment, not role.
        // ─────────────────────────────────────────────────────────────
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();
            $table->string('name');

            // Administrator is created with every organization and cannot be deleted
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        // ─────────────────────────────────────────────────────────────
        // Users — belong to exactly one organization (§4).
        // ─────────────────────────────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->nullable()->after('organization_id')
                  ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('password');

            $table->index('organization_id');
        });

        // Email is unique PER ORGANIZATION, not globally — one person may hold
        // separate accounts in two organizations (§4). Login disambiguates.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['organization_id', 'email']);
        });

        // ─────────────────────────────────────────────────────────────
        // Assignments — where a user may act. Many rows per user (§4).
        // Each row targets EXACTLY ONE scope; the database enforces that,
        // because application-only invariants drift the moment anything
        // writes outside the app (imports, jobs, a console command).
        // ─────────────────────────────────────────────────────────────
        Schema::create('assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')
                  ->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')
                  ->constrained()->cascadeOnDelete();

            $table->foreignUuid('assigned_organization_id')->nullable()
                  ->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('assigned_company_id')->nullable()
                  ->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('assigned_franchise_id')->nullable()
                  ->constrained('franchises')->cascadeOnDelete();

            $table->timestamps();
            $table->index(['organization_id', 'user_id']);
        });

        // XOR: exactly one target. MySQL 8.0.16+ enforces CHECK for real.
        DB::statement('
            ALTER TABLE assignments ADD CONSTRAINT assignments_exactly_one_scope CHECK (
                (assigned_organization_id IS NOT NULL)
              + (assigned_company_id      IS NOT NULL)
              + (assigned_franchise_id    IS NOT NULL) = 1
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'email']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('is_active');
            $table->unique('email');
        });

        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('franchises');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('organizations');
    }
};
