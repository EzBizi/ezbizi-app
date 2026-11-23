<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Project job's costs (Jose with Shoval, 2026-11-23): Parts cost, and Labor
 * as a contractor's set price or a repair team's days, alone or with a helper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('parts_cost_minor')->nullable();
            $table->string('labor_kind', 12)->nullable();
            $table->unsignedBigInteger('labor_price_minor')->nullable();
            $table->unsignedSmallInteger('labor_days')->nullable();
            $table->boolean('labor_helper')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn(['parts_cost_minor', 'labor_kind', 'labor_price_minor', 'labor_days', 'labor_helper']);
        });
    }
};
