<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which connection a tenant's own WhatsApp number came from (Embedded Signup,
 * 2026-10-19). Null for the numbers EzBizi registered itself on its own WABA,
 * which send with the global token. Disconnecting the connection deletes the
 * row, so nothing sends on a number its business has taken back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->uuid('social_connection_id')->nullable()->index()->after('waba_id');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->dropIndex(['social_connection_id']);
            $table->dropColumn('social_connection_id');
        });
    }
};
