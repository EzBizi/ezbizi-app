<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who picked up a customer's call, or whose phones rang when nobody did (Jose,
 * 2026-10-20: "can we also show who picked up as well? like Sasha - Adeline").
 * RingCentral calls; re-reads fill older rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_conversations', function (Blueprint $table) {
            $table->string('answered_by', 120)->nullable()->after('line_name');
            $table->string('rang', 255)->nullable()->after('answered_by');
        });
    }

    public function down(): void
    {
        Schema::table('lead_conversations', fn (Blueprint $table) => $table->dropColumn(['answered_by', 'rang']));
    }
};
