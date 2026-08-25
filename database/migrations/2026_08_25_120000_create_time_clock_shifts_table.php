<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One punch-clock shift per row (Clock in design §6).
 *
 * Soft-delete is not used — a punch is a legal artefact. At most one open
 * shift per person: MySQL has no partial unique index, so `open_slot` is a
 * generated column that is 1 while out is null and NULL once closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_clock_shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->dateTime('clocked_in_at');
            $table->dateTime('clocked_out_at')->nullable();
            $table->unsignedTinyInteger('open_slot')->nullable()->storedAs(
                'case when `clocked_out_at` is null then 1 else null end'
            );
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('organization_id');
            $table->unique(['user_id', 'open_slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_clock_shifts');
    }
};
