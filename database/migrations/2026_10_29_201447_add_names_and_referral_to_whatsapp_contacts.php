<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What WhatsApp tells us about a contact beyond the number (Jose, 2026-10-29):
 * their own profile name, the name the business saved for them in the
 * WhatsApp Business app (coexistence sync), and the Click-to-WhatsApp ad
 * they came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->string('profile_name')->nullable()->after('counterparty');
            $table->string('saved_name')->nullable()->after('profile_name');
            $table->json('referral')->nullable()->after('saved_name');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_contacts', function (Blueprint $table) {
            $table->dropColumn(['profile_name', 'saved_name', 'referral']);
        });
    }
};
