<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setting OVERRIDES (plan §6).
 *
 * Sparse by design: a row exists only where somebody deliberately set a value.
 * Absence means "inherit", not "false" — which is why resolution walks the chain
 * rather than reading one row.
 *
 * The alternative, copying a parent's value into every child on save, breaks the
 * moment the parent changes: children keep a stale copy of a decision that has
 * since been revised, and nobody can tell which values were chosen and which
 * were merely inherited at some point in the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('organization_id');
            $table->enum('scope_type', ['organization', 'company', 'franchise']);
            $table->uuid('scope_id');

            $table->string('key');
            // JSON so one column holds bool/int/string/enum without a type column
            // per row. The definition in code owns the type; this stores the value.
            $table->json('value');

            $table->timestamps();

            // One value per key per scope. Two rows would make resolution
            // order-dependent and therefore unpredictable.
            $table->unique(['scope_type', 'scope_id', 'key']);

            // The resolver's query: give me every override for these scopes.
            $table->index(['organization_id', 'scope_type', 'scope_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
