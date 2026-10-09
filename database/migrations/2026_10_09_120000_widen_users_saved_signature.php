<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A saved signature may be a PNG up to 400 KB; base64 and encryption take that
 * to about 750 KB, and TEXT holds 64 KB (production 1406 "Data too long" on
 * 2026-10-09). MEDIUMTEXT holds 16 MB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->mediumText('saved_signature')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->text('saved_signature')->nullable()->change());
    }
};
