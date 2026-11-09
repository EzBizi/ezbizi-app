<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// What a number is for (reports, support, authentication, company): senders ask
// WhatsAppNumber::forPurpose() instead of guessing. Jose, 2026-11-09: "make all
// the Reports use the Reports Number, so we can leave the EzBizi - Main for the
// AI Support Questions and use the EzBizi - Authentication one for well
// authentications". The four numbers below are Triplex Group's, mapped by their
// WABA names in Meta; anywhere else the update matches nothing.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->string('purpose', 16)->nullable()->after('display_name');
        });

        foreach ([
            '1366054836581646' => 'reports',        // +1 877-450-8572, WABA "EzBizi - Reports"
            '1309270845610102' => 'support',        // +1 954-516-2455, WABA "EzBizi"
            '1254820167721699' => 'authentication', // +1 877-853-6971, WABA "EzBizi - Authentication"
            '828710493664255' => 'company',         // +1 888-519-5311, WABA "A1 Chimney"
        ] as $id => $purpose) {
            DB::table('whatsapp_numbers')->where('phone_number_id', $id)->update(['purpose' => $purpose]);
        }
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
