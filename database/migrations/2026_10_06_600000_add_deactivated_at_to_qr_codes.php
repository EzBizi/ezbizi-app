<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QR codes are deactivated, never deleted (Jose, 2026-10-06: "i dont want
 * anyone deleting qr codes that we printed by accident so instead of deleting
 * them add deactivation instead, so they can be reactivated").
 *
 * A timestamp rather than a flag, so the card can say since when. The code and
 * its destination are untouched, which is what makes reactivating restore it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('last_scanned_at');
        });
    }

    public function down(): void
    {
        Schema::table('qr_codes', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
