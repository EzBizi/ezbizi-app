<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection templates, personal drafts, frozen reports, and per-role grants
 * (docs/superpowers/specs/2026-08-18-inspection-design.md).
 *
 * Named inspections follow contracts: a pointer row plus written-once versions.
 * Drafts are keyed by scope_key, not by nullable job_id — MySQL unique indexes
 * allow several NULLs, which would let two customer-only drafts hide.
 *
 * Customers and jobs soft-delete. A soft delete is an UPDATE, so onDelete
 * restrict/null never fires for them. FKs still restrict a hard DELETE other
 * than the organization cascade. Reports stay readable after a soft delete
 * (evidence). Runs are not resumable against a trashed customer or job — that
 * is a query in New Inspection, not a missing FK action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('intro')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_disk')->nullable();
            $table->uuid('current_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'name'], 'insp_def_org_name_unique');
        });

        Schema::create('inspection_definition_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inspection_definition_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('layout_preset', 32);
            $table->json('screens');
            $table->text('intro')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_disk')->nullable();
            $table->foreignUuid('published_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['inspection_definition_id', 'version'], 'insp_def_ver_unique');
        });

        Schema::create('inspection_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('job_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('inspection_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inspection_definition_version_id')
                ->constrained('inspection_definition_versions')->restrictOnDelete();
            $table->string('scope_key', 120);
            $table->json('answers');
            $table->timestamps();
            $table->unique(['user_id', 'inspection_definition_id', 'scope_key'], 'insp_runs_user_def_scope_unique');
            $table->index(['organization_id', 'user_id'], 'insp_runs_org_user_index');
        });

        Schema::create('inspection_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('job_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('inspection_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inspection_definition_version_id')
                ->constrained('inspection_definition_versions')->restrictOnDelete();
            $table->foreignUuid('submitted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamp('archived_at')->nullable();
            // NOT NULL: a submit that forgets answers must fail rather than look
            // like erasure. Erasure writes `[]`, which a real submit never does.
            $table->json('answers');
            $table->string('layout_preset', 32);
            $table->string('definition_name', 150);
            $table->timestamps();
            $table->index(['organization_id', 'archived_at'], 'insp_reports_org_archived_index');
            $table->index(['organization_id', 'customer_id'], 'insp_reports_org_customer_index');
            $table->index(['organization_id', 'job_id'], 'insp_reports_org_job_index');
        });

        Schema::create('inspection_definition_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('inspection_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['inspection_definition_id', 'role_id'], 'insp_grants_def_role_unique');
            $table->index(['organization_id', 'role_id'], 'insp_grants_org_role_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_definition_grants');
        Schema::dropIfExists('inspection_reports');
        Schema::dropIfExists('inspection_runs');
        Schema::dropIfExists('inspection_definition_versions');
        Schema::dropIfExists('inspection_definitions');
    }
};
