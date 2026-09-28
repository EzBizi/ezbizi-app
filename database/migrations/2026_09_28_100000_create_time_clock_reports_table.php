<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved Work Hours reports (Jose, 2026-09-28): "an automation that basically
 * sends the Total works hour for the week … make sure that what we need to do
 * would be possible to do manually as well … and also make a template, we
 * should be able to choose which roles, or which specific person to add to
 * the report".
 *
 * One row is one report: who is in it (roles, plus people added or left out
 * by name) and who it goes to. The same row is sent by hand from Work Hours ›
 * Reports and by a scheduled automation rule, so the two cannot disagree
 * about what "the weekly report" contains.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_clock_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Everybody holding any of these roles. Empty means no role filter.
            $table->json('role_ids')->nullable();
            // Named people added regardless of role, and named people left out
            // even when their role is in.
            $table->json('include_user_ids')->nullable();
            $table->json('exclude_user_ids')->nullable();
            // Email addresses, or `user:<id>` for somebody in the organization.
            $table->json('recipients')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_clock_reports');
    }
};
