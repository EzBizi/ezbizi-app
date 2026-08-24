<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account Auth Token for X-Twilio-Signature (distinct from the API key secret
 * in `credentials`). Twilio HMAC-signs webhooks with the account auth token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->text('auth_token')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->dropColumn('auth_token');
        });
    }
};
