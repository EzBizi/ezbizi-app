<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client-portal links Workiz makes for an estimate (Jose, 2026-10-21:
 * "eventually we'll send our own estimate links"). Read through the web
 * session, so a column of their own: the v2 sync rewrites `payload` and
 * would drop them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workiz_records', function (Blueprint $table) {
            $table->json('links')->nullable()->after('payload');
            $table->timestamp('links_read_at')->nullable()->after('links');
        });
    }

    public function down(): void
    {
        Schema::table('workiz_records', function (Blueprint $table) {
            $table->dropColumn(['links', 'links_read_at']);
        });
    }
};
