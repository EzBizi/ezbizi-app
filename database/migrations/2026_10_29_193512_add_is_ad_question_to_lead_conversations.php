<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A question the customer tapped from a Click-to-Message ad, not one they
 * typed (A-1, 2026-10-29). Meta sends it as the customer's message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->boolean('is_ad_question')->default(false)->after('is_automatic');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->dropColumn('is_ad_question');
        });
    }
};
