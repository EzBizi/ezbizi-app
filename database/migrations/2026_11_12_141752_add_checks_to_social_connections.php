<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the daily health run and the setup read-back last found, per check
 * (sign_in, messages, routing, setup), so GET integrations/overview never
 * calls a provider live. Shape: {key: {state, reason, checked_at, next_retry_at}}.
 * Written by App\Support\Social\ConnectionChecks only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->json('checks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('checks');
        });
    }
};
