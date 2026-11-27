<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account's profile picture as the provider serves it (TikTok's
 * user.info avatar_url). Signed and short-lived, so it is rewritten on every
 * connect and every token refresh, and never proxied or copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->string('avatar_url', 2048)->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
