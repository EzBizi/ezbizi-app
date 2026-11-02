<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Design 160 (2026-10-29): why somebody hid a conversation or a number, shown beside who did. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->string('hidden_reason', 255)->nullable()->after('hidden_by_user_id'));
        Schema::table('lead_hidden_numbers', fn (Blueprint $table) => $table->string('reason', 255)->nullable()->after('hidden_by_user_id'));
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('hidden_reason'));
        Schema::table('lead_hidden_numbers', fn (Blueprint $table) => $table->dropColumn('reason'));
    }
};
