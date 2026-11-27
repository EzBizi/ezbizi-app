<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who answers to whom, and who owns an area or a step of the work (Jose,
 * 2026-11-03, design 175: "as you recommend with everything"). Read by the
 * Company module's Ownership chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('reports_to_user_id')->nullable()->after('role_id')
                ->constrained('users')->nullOnDelete();
        });

        // kind 'area' (key = Office, Field, Marketing… at a company or branch)
        // or 'process' (key = lead, booking, job, payment, review). One owner
        // per kind + key + place; place null is the whole organization.
        Schema::create('ownerships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->string('key', 60);
            $table->string('scope_type', 20)->nullable();
            $table->uuid('scope_id')->nullable();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'kind', 'key', 'scope_type', 'scope_id'], 'ownerships_one_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ownerships');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to_user_id');
        });
    }
};
