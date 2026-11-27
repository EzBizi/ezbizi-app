<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a handset can draw itself (Android, 2026-11-27): `chat_native` means it
 * builds Chat's notifications natively (call ring, Reply, Mark read), so Chat
 * sends it data-only pushes. Null on every older registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->json('capabilities')->nullable()->after('app');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }
};
