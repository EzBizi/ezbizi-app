<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paid days off a person has already used, entered by HR so a balance starts
 * right (Jose, 2026-10-21: Adeline earns 2 days a month and has already taken
 * some). One row per entry, an optional day and a note; PtoPolicy subtracts
 * them from what was earned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pto_used', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('days', 6, 2);
            $table->date('used_on')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'used_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pto_used');
    }
};
