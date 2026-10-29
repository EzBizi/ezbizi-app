<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Whether WhatsAppIntake answers strangers on this number. Off for a number
// kept in the WhatsApp Business app (coexistence): its staff answer from the phone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->boolean('auto_reply')->default(true)->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_numbers', function (Blueprint $table) {
            $table->dropColumn('auto_reply');
        });
    }
};
