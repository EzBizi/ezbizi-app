<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Used typed straight into Total / Used / Left (Jose, 2026-10-21) is saved as
 * an adjustment row, not by overwriting a number: these say what Used was
 * before and after, so the history reads as "2 → 5, by Eve, because ...".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pto_used', function (Blueprint $table) {
            $table->decimal('set_from', 6, 2)->nullable()->after('note');
            $table->decimal('set_to', 6, 2)->nullable()->after('set_from');
        });
    }

    public function down(): void
    {
        Schema::table('pto_used', function (Blueprint $table) {
            $table->dropColumn(['set_from', 'set_to']);
        });
    }
};
