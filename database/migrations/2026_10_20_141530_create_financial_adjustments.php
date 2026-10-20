<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money facts recorded in EzBizi itself rather than in Airtable: a refund, a
 * dispute or a returned check against a technician, or cents of a repair
 * team's cost moved from one technician to another. The figures read the
 * Airtable copy and these together. Typed in the app or by a confirmed chat
 * command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('franchise_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16); // refund | dispute | check_return | repair_move
            $table->date('date');
            $table->string('technician');
            $table->string('from_technician')->nullable();
            $table->string('team')->nullable();
            $table->bigInteger('cents');
            $table->string('job_number', 64)->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'franchise_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_adjustments');
    }
};
