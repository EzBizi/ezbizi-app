<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Connect chose and found (Jose, 2026-11-12: "doing a connection should
 * take care of the whole process"): the choices it made with their options
 * (a Pinterest board, a Mailchimp audience) and the check it ran right after.
 * Kept apart from `credentials`, which every reconnect replaces whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->json('setup')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_connections', function (Blueprint $table) {
            $table->dropColumn('setup');
        });
    }
};
