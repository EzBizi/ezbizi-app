<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The RGB lighting a manager wants on a PC (2026-10-26). Desired state, kept
 * until changed: the PC's agent applies it whenever it comes online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->json('lighting')->nullable()->after('specs');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_devices', function (Blueprint $table) {
            $table->dropColumn('lighting');
        });
    }
};
