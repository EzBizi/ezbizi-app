<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When `ezbizi:check-connection-health` last got a verdict on a connection
 * (Jose, 2026-11-03: an Integrations card must never say Connected on a dead
 * token). Null until the first check answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('last_checked_at');
        });
    }
};
