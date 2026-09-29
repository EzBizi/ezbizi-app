<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When somebody became a customer -- which `created_at` cannot say.
 *
 * `created_at` is when the ROW was made in EzBizi, so every customer an import
 * wrote reads the import day: 7,357 Workiz rows all say 2026-09-23, and the A1
 * import will do the same to 32,865 more. A1 Tools holds the real date, and it
 * belongs in a column rather than a line of notes, because a date in free text
 * cannot be sorted by, filtered on or shown in a column, and prising it back
 * out of 40,000 notes later is the expensive way round.
 *
 * Nullable: a customer typed in by hand has no earlier history, and "unknown"
 * is the honest value, not the day they were typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('customer_since')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('customer_since');
        });
    }
};
