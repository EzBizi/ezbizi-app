<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The move to a new Twilio account (Jose, 2026-11-19): while a company's
 * numbers move, webhooks arrive signed by the OLD account's token as well as
 * the new one. The old token is kept here, encrypted, and cleared at the end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_connections', function (Blueprint $table) {
            $table->text('previous_auth_token')->nullable()->after('auth_token');
        });
    }

    public function down(): void
    {
        Schema::table('phone_connections', fn (Blueprint $table) => $table->dropColumn('previous_auth_token'));
    }
};
