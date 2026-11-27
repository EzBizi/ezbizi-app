<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Full time or part time, chosen by HR (Eric's suggestion 01a214c3, 2026-11-25).
 * Null means "not chosen": the weekly report then infers it from the schedule
 * (30 hours or more is full time), which is what it did before this existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('hours_type', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hours_type');
        });
    }
};
