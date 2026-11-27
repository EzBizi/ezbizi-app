<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The applicant pipeline (Shoval, 2026-11-20, via Reports and the desk): who
 * applied for which position, from which source, how far they got, their
 * interviews, and what recruiting cost per position. Positions, stages and
 * sources are the tenant's lists (hr.recruiting.*), stored here as their values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 160);
            $table->string('phone', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('position', 120);
            $table->string('source', 120)->nullable();
            $table->string('stage', 120);
            $table->boolean('relevant')->nullable();
            $table->timestamp('applied_at');
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->foreignUuid('hired_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'position', 'applied_at']);
            $table->index(['organization_id', 'stage']);
        });

        Schema::create('applicant_interviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // phone | in_person
            $table->timestamp('scheduled_at');
            $table->boolean('held')->nullable();
            $table->string('outcome', 20)->nullable(); // pass | fail | no_show
            $table->foreignUuid('interviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['applicant_id', 'scheduled_at']);
        });

        Schema::create('recruiting_spend', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('franchise_id')->nullable()->constrained()->nullOnDelete();
            $table->date('month');
            $table->string('position', 120);
            $table->string('source', 120)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'month', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruiting_spend');
        Schema::dropIfExists('applicant_interviews');
        Schema::dropIfExists('applicants');
    }
};
