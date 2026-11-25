<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which app a handset token belongs to: EzBizi, or EzChat, the chat companion
 * (Jose, 2026-11-25). APNs needs the app's own topic (its bundle id), and
 * EzChat is sent chat messages and call rings only. Every existing row is
 * EzBizi's, which is what the default says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->string('app', 10)->default('ezbizi')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn('app');
        });
    }
};
