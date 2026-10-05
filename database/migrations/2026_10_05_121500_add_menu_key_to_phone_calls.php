<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a caller pressed in the menu, and any extension they typed.
 *
 * Jose, 2026-10-05: "we need a report to know which calls were answered,
 * where did the calls come from, what client pressed on the ivr menu". The
 * digits reached the webhook on every press and were never kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->string('menu_key', 8)->nullable()->after('status');
            $table->string('extension_dialed', 8)->nullable()->after('menu_key');
        });
    }

    public function down(): void
    {
        Schema::table('phone_calls', function (Blueprint $table) {
            $table->dropColumn(['menu_key', 'extension_dialed']);
        });
    }
};
