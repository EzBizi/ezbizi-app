<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Google login a connection was signed in with (Jose, 2026-11-02): three
 * sign-ins on 10-29 made 37 Business Profile rows and only display names said
 * whose. Null is "unknown, reconnect to see".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->string('account_email')->nullable()->after('handle'));
    }

    public function down(): void
    {
        Schema::table('social_connections', fn (Blueprint $table) => $table->dropColumn('account_email'));
    }
};
