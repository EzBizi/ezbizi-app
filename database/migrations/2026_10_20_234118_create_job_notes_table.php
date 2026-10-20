<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A job's notes, who and when: step c of moving off Workiz (Jose, 2026-10-20). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('job_id');
            $table->uuid('user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['job_id', 'created_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('job_id')->references('id')->on('jobs')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_notes');
    }
};
