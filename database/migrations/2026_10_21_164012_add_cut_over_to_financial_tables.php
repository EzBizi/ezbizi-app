<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mirrored table can be cut over to EzBizi (Jose, 2026-10-21): from then on
 * EzBizi is where it is kept, Airtable is neither read nor written for it,
 * and its columns can be changed here. Reversible while the Airtable table
 * still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_tables', function (Blueprint $table) {
            $table->timestamp('cut_over_at')->nullable()->after('synced_at');
            $table->foreignUuid('cut_over_by_user_id')->nullable()->after('cut_over_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('financial_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cut_over_by_user_id');
            $table->dropColumn('cut_over_at');
        });
    }
};
