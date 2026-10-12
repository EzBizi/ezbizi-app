<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The values AI wrote on a lead (status, name, ZIP) and when, so the AI mark
 * shows while they stand and the weekly check counts what people changed
 * (Jose, 2026-10-12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->json('ai_set')->nullable());
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('ai_set'));
    }
};
