<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complaints (Jose, 2026-10-20): every bad review, Bad Rating and complaint
 * call in one place, with where it has got to. One row per source thing and
 * kind, so reading the same event twice never makes two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('company_id')->nullable();
            $table->uuid('franchise_id')->nullable();
            $table->string('kind', 20);
            $table->string('source_type', 10);
            $table->uuid('source_id');
            $table->string('status', 20)->default('new');
            $table->uuid('owner_user_id')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('raised_at');
            $table->timestamp('solved_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'kind']);
            $table->index(['organization_id', 'status', 'raised_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->foreign('franchise_id')->references('id')->on('franchises')->nullOnDelete();
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('complaint_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('complaint_id');
            $table->uuid('user_id')->nullable();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['complaint_id', 'created_at']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('complaint_id')->references('id')->on('complaints')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_notes');
        Schema::dropIfExists('complaints');
    }
};
