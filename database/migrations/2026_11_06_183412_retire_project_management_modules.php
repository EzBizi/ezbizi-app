<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Both Project Management modules are gone (Jose, 2026-11-06: delete them, he
 * rebuilds from scratch). This removes what they left in shared tables, the
 * one table Project Management - 2 owned, and the two job columns only Project
 * Management read. `external_company` stays: job booking writes it.
 *
 * Follows 2026_09_03_093000_retire_tasks_module_records: grants first, then
 * permissions; audit_events untouched. down() restores the schema only; the
 * submission rows, grants and settings need the pre-deploy dump.
 */
return new class extends Migration
{
    private const MODULES = ['project_management', 'project_management_2'];

    private const SETTINGS = [
        'jobs.pm_stages', 'jobs.pm_scope_statuses', 'jobs.pm2_pending_status', 'jobs.pm2_submitted_status',
        'jobs.estimate_deadline_days', 'jobs.pm_partly_paid_only', 'jobs.pm_task_group', 'jobs.pm_redo_words',
        'jobs.pm_dispute_words', 'jobs.pm_stopped_days', 'jobs.pm_warn_days', 'jobs.pm_warning_email',
        'jobs.pm_cost_alert_percent', 'jobs.review_reasons',
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->where(function ($q) {
            $q->whereIn('module', self::MODULES)->orWhere('key', 'like', 'jobs.project_management.%');
        })->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        DB::table('platform_module_flags')->whereIn('module_key', self::MODULES)->delete();
        DB::table('platform_help_videos')->whereIn('module_key', self::MODULES)->delete();
        DB::table('settings')->whereIn('key', self::SETTINGS)->delete();

        Schema::dropIfExists('project_management_submissions');

        $drop = array_values(array_filter(['review_reason', 'needs_second_visit'], fn ($c) => Schema::hasColumn('jobs', $c)));
        if ($drop !== []) {
            Schema::table('jobs', fn (Blueprint $table) => $table->dropColumn($drop));
        }
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->string('review_reason', 100)->nullable()->after('tags');
            $table->boolean('needs_second_visit')->default(false)->after('external_company');
        });

        Schema::create('project_management_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('job_id')->constrained()->cascadeOnDelete();
            $table->uuid('automation_event_id')->unique();
            $table->timestamp('submitted_at');
            $table->index(['job_id', 'submitted_at']);
        });
    }
};
